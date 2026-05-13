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

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
require_once DOL_DOCUMENT_ROOT.'/custom/s3backup/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Dashboard box showing the latest S3 backups and their sizes.
 */
class box_s3backup extends ModeleBoxes
{
  public $boxcode  = "s3backuplist";
  public $boximg   = "fa-cloud-upload-alt";
  public $boxlabel = "BoxS3BackupList";
  public $depends  = array("s3backup");

  /**
   * @param DoliDB $db
   * @param string $param
   */
  public function __construct($db, $param = '')
  {
    $this->db = $db;
  }

  /**
   * Populate $info_box_head and $info_box_contents with S3 backup data.
   *
   * @param int $max Maximum number of backups to display
   */
  public function loadBox($max = 5)
  {
    global $langs;

    $langs->load("s3backup@s3backup");

    $this->max = $max;

    $this->info_box_head = array(
      'text'  => $langs->trans("LastS3Backups", $max),
      'limit' => 500,
    );

    $endpoint  = getDolGlobalString('S3BACKUP_ENDPOINT');
    $region    = getDolGlobalString('S3BACKUP_REGION');
    $bucket    = getDolGlobalString('S3BACKUP_BUCKET');
    $accessKey = getDolGlobalString('S3BACKUP_ACCESS_KEY');
    $secretKey = getDolGlobalString('S3BACKUP_SECRET_KEY');

    if (empty($endpoint) || empty($bucket) || empty($accessKey) || empty($secretKey)) {
      $this->info_box_contents[0][0] = array(
        'td'   => 'class="nohover left"',
        'text' => '<span class="opacitymedium">'.$langs->trans("S3BackupNotConfigured").'</span>',
      );
      return;
    }

    try {
      $s3 = new S3Client(array(
        'version'                 => 'latest',
        'region'                  => $region,
        'endpoint'                => $endpoint,
        'use_path_style_endpoint' => true,
        'credentials'             => array(
          'key'    => $accessKey,
          'secret' => $secretKey,
        ),
      ));

      $folders = array();
      $paginator = $s3->getPaginator('ListObjectsV2', array(
        'Bucket'    => $bucket,
        'Delimiter' => '/',
      ));

      foreach ($paginator as $page) {
        foreach (($page['CommonPrefixes'] ?? array()) as $cp) {
          $folderKey  = $cp['Prefix'];
          $folderName = rtrim($folderKey, '/');
          $folders[]  = $folderName;
        }
      }

      rsort($folders);
      $totalCount  = count($folders);
      $displayList = array_slice($folders, 0, $max);

      // Column header row
      $this->info_box_contents[0][0] = array(
        'td'   => 'class="left" style="font-weight:bold;"',
        'text' => $langs->trans("Date"),
      );
      $this->info_box_contents[0][1] = array(
        'td'   => 'class="right" style="font-weight:bold;"',
        'text' => 'DB',
      );
      $this->info_box_contents[0][2] = array(
        'td'   => 'class="right" style="font-weight:bold;"',
        'text' => $langs->trans("S3BackupDocs"),
      );

      $line = 1;
      foreach ($displayList as $folderName) {
        $dt = DateTime::createFromFormat('Ymd_H_i_s', $folderName);
        $dateLabel = $dt ? dol_print_date($dt->getTimestamp(), 'dayhour') : $folderName;

        $dbSize   = 0;
        $docsSize = 0;
        $objects = $s3->listObjectsV2(array(
          'Bucket' => $bucket,
          'Prefix' => $folderName.'/',
        ));
        foreach (($objects['Contents'] ?? array()) as $obj) {
          $key = $obj['Key'];
          if (substr($key, -7) === '/db.bz2') {
            $dbSize = (int) $obj['Size'];
          } elseif (substr($key, -12) === '/docs.tar.gz') {
            $docsSize = (int) $obj['Size'];
          }
        }

        $this->info_box_contents[$line][0] = array(
          'td'   => 'class="left"',
          'text' => $dateLabel,
        );
        $this->info_box_contents[$line][1] = array(
          'td'   => 'class="right"',
          'text' => $dbSize > 0 ? self::formatSize($dbSize) : '-',
        );
        $this->info_box_contents[$line][2] = array(
          'td'   => 'class="right"',
          'text' => $docsSize > 0 ? self::formatSize($docsSize) : '-',
        );

        $line++;
      }

      // Summary footer row
      $this->info_box_contents[$line][0] = array(
        'td'   => 'class="nohover left" colspan="3"',
        'text' => '<span class="opacitymedium">'.$langs->trans("S3BackupTotalCount", $totalCount).'</span>',
        'asis' => 1,
      );
    } catch (AwsException $e) {
      $this->info_box_contents[0][0] = array(
        'td'   => 'class="nohover left"',
        'text' => '<span class="error">'.$langs->trans("S3BackupConnectionError").': '.dol_escape_htmltag($e->getAwsErrorMessage()).'</span>',
        'asis' => 1,
      );
    }
  }

  /**
   * @param array|null $head
   * @param array|null $contents
   * @param int        $nooutput
   * @return string
   */
  public function showBox($head = null, $contents = null, $nooutput = 0)
  {
    return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
  }

  /**
   * Format a byte count as a human-readable string (Ko / Mo / Go).
   *
   * @param int $bytes
   * @return string
   */
  private static function formatSize($bytes)
  {
    if ($bytes >= 1000 * 1000 * 1000) {
      return number_format($bytes / (1000 * 1000 * 1000), 2).' Go';
    }
    if ($bytes >= 1000 * 1000) {
      return number_format($bytes / (1000 * 1000), 2).' Mo';
    }
    if ($bytes >= 1000) {
      return number_format($bytes / 1000, 2).' Ko';
    }
    return $bytes.' o';
  }
}
