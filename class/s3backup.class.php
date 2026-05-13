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

require_once DOL_DOCUMENT_ROOT.'/core/class/utils.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/s3backup/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Handles scheduled database and file backups uploaded to an S3-compatible bucket.
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

  /**
   * Dump the database and upload the archive to S3.
   * Called by the Dolibarr cron scheduler.
   *
   * @return int 0 on success, non-zero on error
   */
  public function backupDatabase()
  {
    global $conf, $langs;

    dol_syslog(__METHOD__, LOG_DEBUG);

    $error = 0;
    $localFile = null;

    try {
      $s3 = $this->buildS3Client();

      $utils = new Utils($this->db);

      // Generate the dump into DOL_DATA_ROOT/admin/backup/
      $result = $utils->dumpDatabase('gz', 'auto', 1, 'auto', 0, 0, 0);
      if ($result < 0) {
        $this->error = 'dumpDatabase failed: '.$utils->error;
        return 1;
      }

      $localFile = $this->findLatestBackupFile('admin/backup', '.sql.gz');
      if (!$localFile) {
        $this->error = 'Could not find the generated database dump file';
        return 1;
      }

      $key = $this->buildKey('db', basename($localFile));
      $this->uploadToS3($s3, $localFile, $key);

      $this->output = 'Database backup uploaded: '.$key;
    } catch (AwsException $e) {
      $error++;
      $this->error = 'S3 upload failed: '.$e->getAwsErrorMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } catch (Exception $e) {
      $error++;
      $this->error = $e->getMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } finally {
      if ($localFile && file_exists($localFile)) {
        dol_delete_file($localFile);
      }
    }

    return $error;
  }

  /**
   * Archive the documents directory and upload the zip to S3.
   * Called by the Dolibarr cron scheduler.
   *
   * @return int 0 on success, non-zero on error
   */
  public function backupFiles()
  {
    global $conf, $langs;

    dol_syslog(__METHOD__, LOG_DEBUG);

    $error = 0;
    $localFile = null;

    try {
      $s3 = $this->buildS3Client();

      $timestamp = dol_print_date(dol_now(), '%Y-%m-%d_%H-%M-%S', 'gmt');
      $filename = $timestamp.'_files.zip';
      $localFile = DOL_DATA_ROOT.'/admin/backup/'.$filename;

      dol_mkdir(DOL_DATA_ROOT.'/admin/backup');

      $result = dol_compress_dir(DOL_DATA_ROOT, $localFile, 'zip', 'admin/backup');
      if ($result <= 0) {
        $this->error = 'dol_compress_dir failed with code: '.$result;
        return 1;
      }

      $key = $this->buildKey('files', $filename);
      $this->uploadToS3($s3, $localFile, $key);

      $this->output = 'Files backup uploaded: '.$key;
    } catch (AwsException $e) {
      $error++;
      $this->error = 'S3 upload failed: '.$e->getAwsErrorMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } catch (Exception $e) {
      $error++;
      $this->error = $e->getMessage();
      dol_syslog(__METHOD__.' - '.$this->error, LOG_ERR);
    } finally {
      if ($localFile && file_exists($localFile)) {
        dol_delete_file($localFile);
      }
    }

    return $error;
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
  // Private helpers
  // ------------------------------------------------------------------

  /**
   * Build and return an S3Client configured from Dolibarr constants.
   */
  private function buildS3Client()
  {
    global $conf;

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
   * Upload a local file to S3 using streaming to handle large archives.
   */
  private function uploadToS3(S3Client $s3, $localFile, $key)
  {
    $s3->putObject(array(
      'Bucket'     => $this->getBucket(),
      'Key'        => $key,
      'SourceFile' => $localFile,
    ));
  }

  /**
   * Build the S3 object key from the configured prefix, a type sub-folder, and a filename.
   */
  private function buildKey($type, $filename)
  {
    $prefix = rtrim(getDolGlobalString('S3BACKUP_PREFIX', 'dolibarr-backup'), '/');
    return $prefix.'/'.$type.'/'.$filename;
  }

  /**
   * Return the configured S3 bucket name.
   */
  private function getBucket()
  {
    return getDolGlobalString('S3BACKUP_BUCKET');
  }

  /**
   * Return the full path of the most recently modified file matching a suffix in a sub-directory of DOL_DATA_ROOT.
   */
  private function findLatestBackupFile($relDir, $suffix)
  {
    $dir = DOL_DATA_ROOT.'/'.$relDir;
    $files = dol_dir_list($dir, 'files', 0, '', '', 'date', SORT_DESC);
    foreach ($files as $file) {
      if (substr($file['name'], -strlen($suffix)) === $suffix) {
        return $file['fullname'];
      }
    }
    return null;
  }
}
