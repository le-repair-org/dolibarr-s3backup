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

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
  $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
  $i--;
  $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
  $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
  $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
  $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
  $res = @include "../../../main.inc.php";
}
if (!$res) {
  die("Include of main fails");
}

global $langs, $user, $conf, $db;

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/custom/s3backup/class/s3backup.class.php";

$langs->loadLangs(array("admin", "s3backup@s3backup"));

$action = GETPOST('action', 'aZ09');

if (!$user->admin) {
  accessforbidden();
}

/*
 * Actions
 */

if ($action === 'save') {
  $fields = array(
    'S3BACKUP_ENDPOINT',
    'S3BACKUP_REGION',
    'S3BACKUP_BUCKET',
    'S3BACKUP_ACCESS_KEY',
    'S3BACKUP_PREFIX',
  );

  foreach ($fields as $field) {
    $value = GETPOST($field, 'alpha');
    dolibarr_set_const($db, $field, $value, 'chaine', 0, '', $conf->entity);
  }

  // Secret key: only update if a new value was provided
  $secret = GETPOST('S3BACKUP_SECRET_KEY', 'alpha');
  if ($secret !== '') {
    dolibarr_set_const($db, 'S3BACKUP_SECRET_KEY', $secret, 'chaine', 0, '', $conf->entity);
  }

  setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
} elseif ($action === 'testconnection') {
  try {
    $backup = new S3Backup($db);
    $backup->testConnection();
    setEventMessages($langs->trans("S3BackupConnectionSuccess"), null, 'mesgs');
  } catch (Exception $e) {
    setEventMessages($langs->trans("S3BackupConnectionError").': '.$e->getMessage(), null, 'errors');
  }
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("S3BackupSetup"), '', '', 0, 0, '', '', '', 'mod-s3backup page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("S3BackupSetup"), $linkback, 'title_setup');

print dol_get_fiche_head(array(), '', $langs->trans("S3BackupSetup"), -1, 'fa-cloud-upload-alt');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("S3BackupConnectionSettings").'</td>';
print '</tr>';

// Endpoint
print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans("S3BackupEndpoint").'</td>';
print '<td><input type="text" name="S3BACKUP_ENDPOINT" class="minwidth500" value="'.htmlspecialchars(getDolGlobalString('S3BACKUP_ENDPOINT')).'" placeholder="https://s3.gra.io.cloud.ovh.net"></td>';
print '</tr>';

// Region
print '<tr class="oddeven">';
print '<td>'.$langs->trans("S3BackupRegion").'</td>';
print '<td><input type="text" name="S3BACKUP_REGION" class="minwidth200" value="'.htmlspecialchars(getDolGlobalString('S3BACKUP_REGION')).'" placeholder="gra"></td>';
print '</tr>';

// Bucket
print '<tr class="oddeven">';
print '<td>'.$langs->trans("S3BackupBucket").'</td>';
print '<td><input type="text" name="S3BACKUP_BUCKET" class="minwidth300" value="'.htmlspecialchars(getDolGlobalString('S3BACKUP_BUCKET')).'"></td>';
print '</tr>';

// Prefix
print '<tr class="oddeven">';
print '<td>'.$langs->trans("S3BackupPrefix").'</td>';
print '<td><input type="text" name="S3BACKUP_PREFIX" class="minwidth300" value="'.htmlspecialchars(getDolGlobalString('S3BACKUP_PREFIX', 'dolibarr-backup')).'"></td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("S3BackupCredentials").'</td>';
print '</tr>';

// Access key
print '<tr class="oddeven">';
print '<td>'.$langs->trans("S3BackupAccessKey").'</td>';
print '<td><input type="text" name="S3BACKUP_ACCESS_KEY" class="minwidth400" value="'.htmlspecialchars(getDolGlobalString('S3BACKUP_ACCESS_KEY')).'"></td>';
print '</tr>';

// Secret key — displayed masked, only updated if a new value is submitted
$hasSecret = (getDolGlobalString('S3BACKUP_SECRET_KEY') !== '');
print '<tr class="oddeven">';
print '<td>'.$langs->trans("S3BackupSecretKey").'</td>';
print '<td>';
print '<input type="password" name="S3BACKUP_SECRET_KEY" class="minwidth400" value="" autocomplete="new-password" placeholder="'.($hasSecret ? $langs->trans("S3BackupSecretKeyPlaceholder") : '').'">';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="tabsAction">';
print '<input type="submit" class="butAction" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Test connection button (separate form to avoid overwriting the secret on test)
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testconnection">';
print '<div class="tabsAction">';
print '<input type="submit" class="butActionDelete" value="'.$langs->trans("S3BackupTestConnection").'">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
