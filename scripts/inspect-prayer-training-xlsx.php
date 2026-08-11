<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? __DIR__.'/../database/imports/Prayer Training.xlsx';
$book = IOFactory::load($path);

echo 'File: '.$path.PHP_EOL;
echo 'Sheets: '.implode(', ', $book->getSheetNames()).PHP_EOL;

foreach ($book->getSheetNames() as $name) {
  $sheet = $book->getSheetByName($name);
  $rows = $sheet->toArray(null, true, true, true);
  echo PHP_EOL.'=== Sheet: '.$name.' (rows: '.count($rows).') ==='.PHP_EOL;
  $shown = 0;
  foreach ($rows as $i => $row) {
    $vals = [];
    foreach ($row as $col => $value) {
      $vals[$col] = is_scalar($value) ? trim((string) $value) : '';
    }
    if (implode('', $vals) === '') {
      continue;
    }
    echo 'Row '.$i.': '.json_encode($vals, JSON_UNESCAPED_UNICODE).PHP_EOL;
    $shown++;
    if ($shown >= 50) {
      echo '... truncated ...'.PHP_EOL;
      break;
    }
  }
}
