#!/usr/bin/env php
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

/**
 * CLI restore script: downloads a backup from S3 and restores documents.
 *
 * Database restoration is intentionally left to the caller (restore_from_backup.sh)
 * which pipes the decompressed SQL into the mariadb container directly.
 *
 * Required environment variables:
 *   S3BACKUP_ENDPOINT, S3BACKUP_REGION, S3BACKUP_BUCKET
 *   S3BACKUP_ACCESS_KEY, S3BACKUP_SECRET_KEY
 *
 * Optional environment variables:
 *   DOL_DATA_ROOT  Documents root path (default: /var/www/documents)
 *
 * Usage:
 *   php restore.php <YYYYMMDD_HH_MM_SS>
 *
 * The decompressed SQL file is saved to /tmp/s3restore_<timestamp>/db.sql
 * and NOT cleaned up — the caller is responsible for cleanup after DB injection.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// -------------------------------------------------------
// Helpers
// -------------------------------------------------------

function log_info(string $msg): void
{
  echo "[INFO] $msg\n";
}

function log_error(string $msg): void
{
  fwrite(STDERR, "[ERROR] $msg\n");
}

function run_cmd(string $cmd, string $desc): void
{
  exec($cmd, $output, $ret);
  if ($ret !== 0) {
    throw new RuntimeException("$desc failed (exit code $ret)");
  }
}

function require_env(string $name): string
{
  $val = getenv($name);
  if ($val === false || $val === '') {
    log_error("Missing required environment variable: $name");
    exit(1);
  }
  return $val;
}

// -------------------------------------------------------
// Argument parsing
// -------------------------------------------------------

if (empty($argv[1])) {
  log_error("Usage: php restore.php <YYYYMMDD_HH_MM_SS>");
  exit(1);
}

$timestamp = $argv[1];
if (!preg_match('/^\d{8}_\d{2}_\d{2}_\d{2}$/', $timestamp)) {
  log_error("Invalid timestamp format. Expected: YYYYMMDD_HH_MM_SS, got: $timestamp");
  exit(1);
}

// -------------------------------------------------------
// Configuration from environment
// -------------------------------------------------------

$endpoint = require_env('S3BACKUP_ENDPOINT');
$region   = require_env('S3BACKUP_REGION');
$bucket   = require_env('S3BACKUP_BUCKET');
$accessKey = require_env('S3BACKUP_ACCESS_KEY');
$secretKey = require_env('S3BACKUP_SECRET_KEY');
$docsRoot  = getenv('DOL_DATA_ROOT') ?: '/var/www/documents';

$tmpDir  = "/tmp/s3restore_$timestamp";
$dbBz2   = "$tmpDir/db.bz2";
$dbSql   = "$tmpDir/db.sql";
$docsTgz = "$tmpDir/docs.tar.gz";

// -------------------------------------------------------
// Main
// -------------------------------------------------------

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

  if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
    throw new RuntimeException("Cannot create temp directory: $tmpDir");
  }

  // Download backup files from S3
  log_info("Downloading $timestamp/db.bz2 from S3...");
  $s3->getObject(array('Bucket' => $bucket, 'Key' => "$timestamp/db.bz2", 'SaveAs' => $dbBz2));

  log_info("Downloading $timestamp/docs.tar.gz from S3...");
  $s3->getObject(array('Bucket' => $bucket, 'Key' => "$timestamp/docs.tar.gz", 'SaveAs' => $docsTgz));

  log_info("S3 download complete.");

  // Decompress database dump
  log_info("Decompressing database dump...");
  run_cmd(
    "bunzip2 -c " . escapeshellarg($dbBz2) . " > " . escapeshellarg($dbSql),
    "DB decompression"
  );
  unlink($dbBz2);

  $sqlSize = filesize($dbSql);
  if ($sqlSize < 1024) {
    throw new RuntimeException("Decompressed SQL file is too small ($sqlSize bytes) — dump may be corrupt");
  }
  log_info("SQL file ready: " . number_format($sqlSize / 1024 / 1024, 1) . " MB");

  // Restore documents volume
  log_info("Cleaning documents directory: $docsRoot");
  run_cmd("rm -rf " . escapeshellarg($docsRoot) . "/*", "Documents cleanup");

  log_info("Extracting documents archive...");
  run_cmd(
    "tar -xzf " . escapeshellarg($docsTgz) . " -C " . escapeshellarg($docsRoot),
    "Documents extraction"
  );
  unlink($docsTgz);

  log_info("Documents restored to $docsRoot");
  log_info("SQL file ready for injection at: $dbSql");

} catch (AwsException $e) {
  log_error("S3 error: " . $e->getAwsErrorMessage());
  exit(1);
} catch (Exception $e) {
  log_error($e->getMessage());
  exit(1);
}

exit(0);
