<?php
/* Copyright (C) 2025 Le Repair
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/s3backup/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Handles scheduled database and file backups uploaded to an S3-compatible bucket.
 *
 * S3 layout:
 *   [{prefix}/]YYYYMMDD_HH_MM_SS/db.bz2
 *   [{prefix}/]YYYYMMDD_HH_MM_SS/docs.tar.gz
 */
class S3Backup
{
  /** @var DoliDB */
  public $db;

  /** @var string Output message for cron log */
  public $output = '';

  /** @var string Error message for cron log */
  public $error = '';

  /**
   * @param DoliDB $db Database handler
   */
  public function __construct($db)
  {
    $this->db = $db;
  }

  // ------------------------------------------------------------------
  // Public cron-callable methods
  // ------------------------------------------------------------------

  /**
   * Dump the database and archive the documents directory, then upload both
   * under the same timestamp folder: YYYYMMDD_HH_MM_SS/db.bz2 and docs.tar.gz.
   *
   * @return int 0 on success, non-zero on error
   */
  public function backup()
  {
    dol_syslog(__METHOD__, LOG_DEBUG);

    $error    = 0;
    $dbFile   = null;
    $docsFile = null;

    try {
      $s3        = $this->buildS3Client();
      $timestamp = date('Ymd_H_i_s');

      $dbFile   = $this->dumpDatabaseToFile($timestamp);
      $docsFile = $this->archiveFilesToFile($timestamp);

      $this->uploadToS3($s3, $dbFile,   $this->buildKey($timestamp, 'db.bz2'));
      $this->uploadToS3($s3, $docsFile, $this->buildKey($timestamp, 'docs.tar.gz'));

      $this->output = 'Backup uploaded: '.$timestamp.'/db.bz2 + docs.tar.gz';
    } catch (AwsException $e) {
      $error++;
      $this->error = 'S3 upload failed: '.$e->getAwsErrorMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } catch (Exception $e) {
      $error++;
      $this->error = $e->getMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } finally {
      if ($dbFile && file_exists($dbFile)) {
        dol_delete_file($dbFile);
      }
      if ($docsFile && file_exists($docsFile)) {
        dol_delete_file($docsFile);
      }
    }

    return $error;
  }

  /**
   * Apply the two-tier retention policy to all backup folders in the S3 bucket.
   *
   * - Folders within RETENTION_DAYS: kept as-is.
   * - Older folders: only the most recent folder per calendar month is kept.
   *
   * @return int 0 on success, non-zero on error
   */
  public function pruneBackups()
  {
    dol_syslog(__METHOD__, LOG_DEBUG);

    try {
      $s3      = $this->buildS3Client();
      $deleted = $this->pruneOldBackupFolders($s3);
      $this->output = 'Pruned: '.$deleted.' old backup folder(s)';
    } catch (AwsException $e) {
      $this->error = 'S3 prune failed: '.$e->getAwsErrorMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
      return 1;
    } catch (Exception $e) {
      $this->error = $e->getMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
      return 1;
    }

    return 0;
  }

  /**
   * Test the S3 connection by verifying the configured bucket is accessible.
   *
   * @return bool True if the connection succeeds
   * @throws AwsException|Exception on failure
   */
  public function testConnection()
  {
    $s3 = $this->buildS3Client();
    $s3->headBucket(array('Bucket' => $this->getBucket()));
    return true;
  }

  // ------------------------------------------------------------------
  // Private — dump and archive helpers
  // ------------------------------------------------------------------

  /**
   * Run mysqldump, compress with bzip2, and write to a temp file.
   *
   * Uses --set-gtid-purged=OFF and redirects stderr to /dev/null so that
   * non-fatal warnings do not corrupt the dump or trigger false errors.
   *
   * @return string Absolute path to the generated .bz2 file
   * @throws Exception on configuration or execution failure
   */
  private function dumpDatabaseToFile($timestamp)
  {
    global $db;
    global $dolibarr_main_db_name, $dolibarr_main_db_host, $dolibarr_main_db_user,
           $dolibarr_main_db_port, $dolibarr_main_db_pass, $dolibarr_main_db_character_set;

    $cmddump = getDolGlobalString('SYSTEMTOOLS_MYSQLDUMP') ?: $db->getPathOfDump();
    if (!$cmddump) {
      throw new Exception('mysqldump binary not found. Set SYSTEMTOOLS_MYSQLDUMP in Dolibarr admin.');
    }
    $cmddump = preg_replace('/[\$%]/', '', $cmddump);

    $outputDir  = DOL_DATA_ROOT.'/admin/backup';
    $outputFile = $outputDir.'/'.$timestamp.'_db.bz2';

    dol_mkdir($outputDir);

    $pass = str_replace(array('"', '`', '$'), array('\"', '\`', '\$'), (string) $dolibarr_main_db_pass);

    $args  = '--single-transaction';
    $args .= ' --skip-lock-tables';
    $args .= ' --add-drop-table';
    $args .= ' -K';
    $args .= ' --hex-blob';
    $args .= ' -c -e';
    $args .= ' --no-tablespaces';
    $args .= ' -h '.escapeshellarg((string) $dolibarr_main_db_host);
    $args .= ' -u '.escapeshellarg((string) $dolibarr_main_db_user);
    if (!empty($dolibarr_main_db_port)) {
      $args .= ' -P '.(int) $dolibarr_main_db_port.' --protocol=tcp';
    }
    $charset = ($dolibarr_main_db_character_set === 'utf8mb4') ? 'utf8mb4' : 'utf8';
    $args .= ' --default-character-set='.escapeshellarg($charset);
    if (!empty($dolibarr_main_db_pass)) {
      $args .= ' -p"'.$pass.'"';
    }
    $args .= ' '.escapeshellarg((string) $dolibarr_main_db_name);

    $cmd = 'bash -c '.escapeshellarg('set -o pipefail; '.escapeshellarg($cmddump).' '.$args.' 2>/dev/null | bzip2 > '.escapeshellarg($outputFile));

    exec($cmd, $cmdOutput, $retval);

    if ($retval !== 0 || !file_exists($outputFile) || filesize($outputFile) < 1024) {
      throw new Exception('mysqldump failed or produced an unexpectedly small output file (exit code: '.$retval.', size: '.(file_exists($outputFile) ? filesize($outputFile) : 'missing').' bytes)');
    }

    return $outputFile;
  }

  /**
   * Archive DOL_DATA_ROOT with tar+gzip and write to a temp file.
   *
   * @return string Absolute path to the generated .tar.gz file
   * @throws Exception on execution failure
   */
  private function archiveFilesToFile($timestamp)
  {
    $outputDir  = DOL_DATA_ROOT.'/admin/backup';
    $outputFile = $outputDir.'/'.$timestamp.'_docs.tar.gz';

    dol_mkdir($outputDir);

    $stderrFile = $outputDir.'/'.$timestamp.'_docs.stderr';

    // Exclude the backup directory itself to avoid recursive inclusion
    $cmd = 'tar -czf '.escapeshellarg($outputFile)
      .' --exclude='.escapeshellarg('./admin/backup')
      .' -C '.escapeshellarg(DOL_DATA_ROOT).' . 2>'.escapeshellarg($stderrFile);

    exec($cmd, $cmdOutput, $retval);

    $stderr = file_exists($stderrFile) ? trim((string) file_get_contents($stderrFile)) : '';
    if (file_exists($stderrFile)) {
      unlink($stderrFile);
    }
    if ($stderr !== '') {
      dol_syslog(__METHOD__.' - tar stderr: '.$stderr, LOG_WARNING);
    }

    // GNU tar exits 1 for non-fatal warnings (e.g. files changed during archiving),
    // and 2 for fatal errors. Exit 1 is acceptable on a live filesystem.
    if ($retval > 1 || !file_exists($outputFile) || filesize($outputFile) === 0) {
      throw new Exception('tar failed with exit code '.$retval.($stderr ? ': '.$stderr : ''));
    }

    return $outputFile;
  }

  // ------------------------------------------------------------------
  // Private — S3 helpers
  // ------------------------------------------------------------------

  /**
   * Build and return an S3Client configured from Dolibarr constants.
   */
  private function buildS3Client()
  {
    return new S3Client(array(
      'version'                 => 'latest',
      'region'                  => getDolGlobalString('S3BACKUP_REGION'),
      'endpoint'                => getDolGlobalString('S3BACKUP_ENDPOINT'),
      'use_path_style_endpoint' => true,
      'credentials'             => array(
        'key'    => getDolGlobalString('S3BACKUP_ACCESS_KEY'),
        'secret' => getDolGlobalString('S3BACKUP_SECRET_KEY'),
      ),
    ));
  }

  /**
   * Upload a local file to S3.
   */
  private function uploadToS3(S3Client $s3, $localFile, $key)
  {
    $s3->putObject(array(
      'Bucket'       => $this->getBucket(),
      'Key'          => $key,
      'SourceFile'   => $localFile,
      'StorageClass' => 'STANDARD_IA',
    ));
  }

  /**
   * Build an S3 object key: TIMESTAMP/filename
   */
  private function buildKey($timestamp, $filename)
  {
    return $timestamp.'/'.$filename;
  }

  /**
   * Return the configured S3 bucket name.
   */
  private function getBucket()
  {
    return getDolGlobalString('S3BACKUP_BUCKET');
  }

  /**
   * Return the retention plan without deleting anything.
   *
   * Each entry has keys: 'key', 'name', 'date'.
   * 'monthly' entries also have a 'month' key (e.g. '2026-03').
   *
   * @return array{recent: array, monthly: array, delete: array}
   * @throws AwsException|Exception
   */
  public function getPrunePlan()
  {
    $s3 = $this->buildS3Client();
    return $this->buildPrunePlan($s3);
  }

  /**
   * List all timestamp folders in the bucket, apply two-tier retention,
   * and delete expired folders.
   *
   * @return int Number of deleted folders
   */
  private function pruneOldBackupFolders(S3Client $s3)
  {
    $bucket = $this->getBucket();
    $plan   = $this->buildPrunePlan($s3);

    $deletedFolders = 0;
    foreach ($plan['delete'] as $f) {
      $objects = array();
      $objPaginator = $s3->getPaginator('ListObjectsV2', array(
        'Bucket' => $bucket,
        'Prefix' => $f['key'],
      ));
      foreach ($objPaginator as $objPage) {
        foreach (($objPage['Contents'] ?? array()) as $obj) {
          $objects[] = array('Key' => $obj['Key']);
        }
      }

      foreach (array_chunk($objects, 1000) as $batch) {
        $s3->deleteObjects(array(
          'Bucket' => $bucket,
          'Delete' => array('Objects' => $batch),
        ));
      }

      $deletedFolders++;
    }

    return $deletedFolders;
  }

  /**
   * Compute which folders to keep and which to delete according to the
   * two-tier retention policy, without performing any deletion.
   *
   * @return array{recent: array, monthly: array, delete: array}
   */
  private function buildPrunePlan(S3Client $s3)
  {
    $bucket      = $this->getBucket();
    $retDays     = max(1, (int) getDolGlobalString('S3BACKUP_RETENTION_DAYS', 30));
    $dailyCutoff = time() - $retDays * 86400;

    $folders = array();
    $paginator = $s3->getPaginator('ListObjectsV2', array(
      'Bucket'    => $bucket,
      'Delimiter' => '/',
    ));
    foreach ($paginator as $page) {
      foreach (($page['CommonPrefixes'] ?? array()) as $cp) {
        $folderKey  = $cp['Prefix'];
        $folderName = basename(rtrim($folderKey, '/'));
        $fileDate   = DateTime::createFromFormat('Ymd', substr($folderName, 0, 8));
        if (!$fileDate) {
          continue;
        }
        $folders[] = array('key' => $folderKey, 'name' => $folderName, 'date' => $fileDate->getTimestamp());
      }
    }

    $recent = array_values(array_filter($folders, function ($f) use ($dailyCutoff) {
      return $f['date'] >= $dailyCutoff;
    }));

    $old = array_values(array_filter($folders, function ($f) use ($dailyCutoff) {
      return $f['date'] < $dailyCutoff;
    }));

    // Keep the most recent folder per calendar month
    $byMonth = array();
    foreach ($old as $f) {
      $month = date('Y-m', $f['date']);
      if (!isset($byMonth[$month]) || $f['date'] > $byMonth[$month]['date']) {
        $byMonth[$month] = $f;
      }
    }

    $monthly  = array();
    $toDelete = array();
    $keepKeys = array_column($byMonth, 'key');
    foreach ($old as $f) {
      if (in_array($f['key'], $keepKeys, true)) {
        $monthly[] = $f + array('month' => date('Y-m', $f['date']));
      } else {
        $toDelete[] = $f;
      }
    }

    usort($recent,   fn($a, $b) => $b['date'] - $a['date']);
    usort($monthly,  fn($a, $b) => $b['date'] - $a['date']);
    usort($toDelete, fn($a, $b) => $b['date'] - $a['date']);

    return array('recent' => $recent, 'monthly' => $monthly, 'delete' => $toDelete);
  }
}
