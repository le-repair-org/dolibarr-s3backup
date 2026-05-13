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

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module S3backup
 */
class modS3backup extends DolibarrModules
{
  /**
   * @param DoliDB $db Database handler
   */
  public function __construct($db)
  {
    global $langs, $conf;

    $this->db = $db;

    $this->numero = 500020;
    $this->rights_class = 's3backup';
    $this->family = "other";
    $this->module_position = '90';
    $this->name = preg_replace('/^mod/i', '', get_class($this));
    $this->description = "S3 backup module for Dolibarr (database + files)";
    $this->version = '1.0';
    $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
    $this->picto = 'fa-cloud-upload-alt';

    $this->module_parts = array(
      'triggers' => 0,
      'login'    => 0,
      'css'      => array(),
      'js'       => array(),
    );

    $this->dirs = array();
    $this->config_page_url = array("setup.php@s3backup");
    $this->depends = array();
    $this->requiredby = array();
    $this->conflictwith = array();
    $this->phpmin = array(8, 2);
    $this->need_dolibarr_version = array(19, -3);

    // S3 connection settings
    $this->const = array(
      1 => array('S3BACKUP_ENDPOINT',      'chaine', '',                'S3-compatible endpoint URL (e.g. https://s3.gra.io.cloud.ovh.net)', 0, 'current', 1),
      2 => array('S3BACKUP_REGION',        'chaine', '',                'S3 region (e.g. gra)', 0, 'current', 1),
      3 => array('S3BACKUP_BUCKET',        'chaine', '',                'S3 bucket name', 0, 'current', 1),
      4 => array('S3BACKUP_ACCESS_KEY',    'chaine', '',                'S3 access key', 0, 'current', 1),
      5 => array('S3BACKUP_SECRET_KEY',    'chaine', '',                'S3 secret key', 0, 'current', 1),
      6 => array('S3BACKUP_PREFIX',        'chaine', 'dolibarr-backup', 'Key prefix (folder) inside the bucket', 0, 'current', 1),
      7 => array('S3BACKUP_RETENTION_DAYS','chaine', '30',              'Days to keep all daily backups; older ones are kept at one per calendar month', 0, 'current', 1),
    );

    $this->cronjobs = array(
      0 => array(
        'label'         => 'S3 database backup',
        'jobtype'       => 'method',
        'class'         => '/s3backup/class/s3backup.class.php',
        'objectname'    => 'S3Backup',
        'method'        => 'backupDatabase',
        'parameters'    => '',
        'comment'       => 'Dump the database and upload it to the S3 bucket',
        'frequency'     => 1,
        'unitfrequency' => 86400,
        'status'        => 0,
        'test'          => 'isModEnabled("s3backup")',
        'priority'      => 50,
      ),
      1 => array(
        'label'         => 'S3 files backup',
        'jobtype'       => 'method',
        'class'         => '/s3backup/class/s3backup.class.php',
        'objectname'    => 'S3Backup',
        'method'        => 'backupFiles',
        'parameters'    => '',
        'comment'       => 'Archive the documents directory and upload it to the S3 bucket',
        'frequency'     => 1,
        'unitfrequency' => 604800,
        'status'        => 0,
        'test'          => 'isModEnabled("s3backup")',
        'priority'      => 50,
      ),
    );

    $this->rights = array();
    $this->tabs = array();
    $this->menu = array();
  }
}
