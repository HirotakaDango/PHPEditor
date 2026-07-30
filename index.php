<?php
ini_set('session.gc_maxlifetime', 31536000);
ini_set('session.cookie_lifetime', 31536000);

session_start([
  'cookie_lifetime' => 31536000,
  'gc_maxlifetime' => 31536000,
  'cookie_httponly' => true,
  'cookie_samesite' => 'Lax'
]);

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
  session_destroy();
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
  $password = $_POST['login_password'];
  if ($password === 'admin') {
    $_SESSION['auth_role'] = 'admin';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  } elseif ($password === 'demo') {
    $_SESSION['auth_role'] = 'demo';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  } else {
    $authError = 'Invalid password.';
  }
}

$isLoggedIn = isset($_SESSION['auth_role']);
$isAdmin = $isLoggedIn && $_SESSION['auth_role'] === 'admin';
$isDemo = $isLoggedIn && $_SESSION['auth_role'] === 'demo';

if (!$isLoggedIn && (!isset($_GET['api']) || $_SERVER['REQUEST_METHOD'] === 'POST')) {
  ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>PHPEditor - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
      body {
        background-color: #030303;
        color: #ffffff;
        font-family: system-ui, -apple-system, sans-serif;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .login-card {
        background: #121212;
        border: 1px solid #2d2d2d;
        border-radius: 16px;
        padding: 32px;
        width: 100%;
        max-width: 380px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.8);
      }
    </style>
  </head>
  <body>
    <div class="login-card text-center">
      <i class="bi bi-code-slash text-danger" style="font-size: 3.5rem;"></i>
      <h4 class="fw-bold mt-3 mb-1">PHPEditor</h4>
      <p class="text-secondary small mb-4">Enter password to unlock workspace</p>
      <?php if ($authError): ?>
        <div class="alert alert-danger py-2 small fw-bold mb-3"><?php echo htmlspecialchars($authError); ?></div>
      <?php endif; ?>
      <form method="POST" action="">
        <div class="mb-3">
          <input type="password" name="login_password" class="form-control bg-dark text-white border-secondary py-2 text-center" placeholder="Password" required autofocus />
        </div>
        <button type="submit" class="btn btn-danger w-100 fw-bold py-2">Unlock Workspace</button>
      </form>
      <div class="mt-4 pt-3 border-top border-secondary text-secondary small">
        <div><strong>admin</strong> &bull; Full Access</div>
        <div><strong>demo</strong> &bull; Read-Only Access</div>
      </div>
    </div>
  </body>
</html>
  <?php
  exit;
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$baseDir = __DIR__;

function isValidPath($base, $path) {
  $realBase = realpath($base);
  $realPath = realpath($path);
  if ($realPath === false) return false;
  return strpos($realPath, $realBase) === 0;
}

function recursiveDelete($dir) {
  if (!file_exists($dir)) return true;
  if (!is_dir($dir)) return unlink($dir);
  foreach (scandir($dir) as $item) {
    if ($item == '.' || $item == '..') continue;
    if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item)) return false;
  }
  return rmdir($dir);
}

function recursiveCopy($src, $dst) {
  if (is_dir($src)) {
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $items = scandir($src);
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') continue;
      recursiveCopy($src . '/' . $item, $dst . '/' . $item);
    }
  } else if (file_exists($src)) {
    copy($src, $dst);
  }
}

function generateUniqueFileName($dir, $filename) {
  $baseName = pathinfo($filename, PATHINFO_FILENAME);
  $extension = pathinfo($filename, PATHINFO_EXTENSION);
  $extPart = $extension ? '.' . $extension : '';
  $counter = 1;
  while (file_exists($dir . '/' . $baseName . '_(' . $counter . ')' . $extPart)) {
    $counter++;
  }
  return $baseName . '_(' . $counter . ')' . $extPart;
}

function generateUniqueFolderName($dir, $foldername) {
  $counter = 1;
  while (is_dir($dir . '/' . $foldername . '_(' . $counter . ')')) {
    $counter++;
  }
  return $foldername . '_(' . $counter . ')';
}

function save_file_version($filepath) {
  global $baseDir;
  if (!file_exists($filepath) || is_dir($filepath)) return;
  $filename = basename($filepath);
  $verDir = $baseDir . '/.file_version/' . $filename;
  if (!is_dir($verDir)) @mkdir($verDir, 0755, true);
  $date = date('Y-m-d_H-i-s');
  @copy($filepath, $verDir . '/' . $filename . '_' . $date);
}

function formatBytes($bytes, $precision = 2) {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= (1 << (10 * $pow));
  return round($bytes, $precision) . ' ' . $units[$pow];
}

function streamFileRange($filePath) {
  $size = filesize($filePath);
  $length = $size;
  $start = 0;
  $end = $size - 1;

  $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
  $mimeTypes = [
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    'txt' => 'text/plain', 'html' => 'text/html', 'css' => 'text/css',
    'js' => 'application/javascript', 'json' => 'application/json', 'php' => 'text/html'
  ];
  $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

  header("Content-Disposition: inline; filename=\"" . basename($filePath) . "\"");
  header("Accept-Ranges: bytes");

  if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;
    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
      header('HTTP/1.1 416 Requested Range Not Satisfiable');
      header("Content-Range: bytes $start-$end/$size");
      exit;
    }
    if ($range[0] === '-') {
      $c_start = $size - substr($range, 1);
    } else {
      $range = explode('-', $range);
      $c_start = $range[0];
      $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
      header('HTTP/1.1 416 Requested Range Not Satisfiable');
      header("Content-Range: bytes $start-$end/$size");
      exit;
    }
    $start = $c_start;
    $end = $c_end;
    $length = $end - $start + 1;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
  }
  header("Content-Length: " . $length);
  header("Content-Type: " . $mime);

  $fp = @fopen($filePath, 'rb');
  if ($fp) {
    fseek($fp, $start);
    $bytesLeft = $length;
    $bufferSize = 1024 * 8;
    while (!feof($fp) && $bytesLeft > 0) {
      if (connection_aborted()) break;
      $readSize = min($bufferSize, $bytesLeft);
      $data = fread($fp, $readSize);
      if ($data === false || strlen($data) === 0) break;
      echo $data;
      flush();
      $bytesLeft -= strlen($data);
    }
    fclose($fp);
  }
}

if (isset($_GET['api'])) {
  $action = $_GET['action'] ?? '';

  if (!in_array($action, ['stream', 'download'])) {
    header('Content-Type: application/json');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $postAction = $input['action'] ?? $action;
    $absPath = $baseDir;

    $clientCsrf = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($clientCsrf) || !hash_equals($_SESSION['csrf_token'], $clientCsrf)) {
      echo json_encode(['success' => false, 'error' => 'Security Violation: CSRF token missing or invalid.']);
      exit;
    }

    if ($isDemo && !in_array($postAction, ['get_versions'])) {
      echo json_encode(['success' => false, 'error' => 'Demo mode: Read-only access. Cannot modify files.']);
      exit;
    }

    if (!empty($_GET['path'])) {
      $reqPath = $baseDir . '/' . $_GET['path'];
      if (isValidPath($baseDir, $reqPath)) $absPath = $reqPath;
    }

    try {
      switch ($postAction) {
        case 'add_file':
          $name = $input['name'] ?? '';
          $full = $absPath . '/' . $name;
          if (file_exists($full)) throw new Exception('File exists');
          file_put_contents($full, '');
          echo json_encode(['success' => true]);
          break;
        case 'add_folder':
          $name = $input['name'] ?? '';
          $full = $absPath . '/' . $name;
          if (file_exists($full)) throw new Exception('Folder exists');
          mkdir($full, 0755, true);
          echo json_encode(['success' => true]);
          break;
        case 'write':
          $file = $input['file'] ?? '';
          $content = $input['content'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full)) throw new Exception('Invalid file');
          save_file_version($full);
          file_put_contents($full, $content);
          echo json_encode(['success' => true]);
          break;
        case 'rename':
          $old = $input['old'] ?? '';
          $new = $input['new'] ?? '';
          $oldFull = $baseDir . '/' . $old;
          $newFull = dirname($oldFull) . '/' . $new;
          if (!isValidPath($baseDir, $oldFull)) throw new Exception('Invalid source');
          rename($oldFull, $newFull);
          echo json_encode(['success' => true]);
          break;
        case 'trash':
        case 'delete':
          $items = $input['items'] ?? [];
          foreach ($items as $itemPath) {
            $full = $baseDir . '/' . $itemPath;
            if (isValidPath($baseDir, $full)) recursiveDelete($full);
          }
          echo json_encode(['success' => true]);
          break;
        case 'copy_items':
        case 'move_items':
          $items = $input['items'] ?? [];
          $target = $input['target'] ?? '';
          $targetDir = rtrim($baseDir . '/' . $target, '/');
          if (!isValidPath($baseDir, $targetDir)) throw new Exception('Invalid target');

          clearstatcache();
          foreach ($items as $item) {
            $src = $baseDir . '/' . $item;
            if (!isValidPath($baseDir, $src) || !file_exists($src)) continue;

            if (is_dir($src) && strpos($targetDir . '/', $src . '/') === 0) {
              throw new Exception("Cannot copy or move a folder into its own subfolder.");
            }

            $destName = basename($item);
            $dest = $targetDir . '/' . $destName;

            if (file_exists($dest)) {
              if (is_dir($src)) {
                $destName = generateUniqueFolderName($targetDir, $destName);
              } else {
                $destName = generateUniqueFileName($targetDir, $destName);
              }
              $dest = $targetDir . '/' . $destName;
            }

            if ($postAction === 'move_items') {
              if ($src !== $dest) rename($src, $dest);
            } else {
              recursiveCopy($src, $dest);
            }
          }
          echo json_encode(['success' => true]);
          break;
        case 'zip_items':
          $items = $input['items'] ?? [];
          if (empty($items)) throw new Exception('No items selected');
          $zipName = (count($items) === 1) ? basename($items[0]) . '.zip' : 'Archive_' . date('Ymd_His') . '.zip';
          $target = $absPath . '/' . $zipName;
          $zip = new ZipArchive();
          if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            foreach ($items as $item) {
              $src = $baseDir . '/' . $item;
              if (is_file($src)) $zip->addFile($src, basename($src));
              elseif (is_dir($src)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $f) {
                  if ($f->isFile()) $zip->addFile($f->getPathname(), basename($src) . '/' . str_replace($src . '/', '', $f->getPathname()));
                }
              }
            }
            $zip->close();
          }
          echo json_encode(['success' => true]);
          break;
        case 'unzip':
          $item = $input['item'] ?? '';
          $src = $baseDir . '/' . $item;
          if (!isValidPath($baseDir, $src) || !file_exists($src) || strtolower(pathinfo($src, PATHINFO_EXTENSION)) !== 'zip') {
            throw new Exception('Invalid zip file');
          }
          $zip = new ZipArchive;
          if ($zip->open($src) === TRUE) {
            $folderName = pathinfo($src, PATHINFO_FILENAME);
            $parentDir = dirname($src);
            $extractTarget = $parentDir . '/' . $folderName;
            if (!file_exists($extractTarget)) mkdir($extractTarget, 0755, true);
            $zip->extractTo($extractTarget);
            $zip->close();
            echo json_encode(['success' => true]);
          } else {
            throw new Exception('Failed to extract ZIP');
          }
          break;
        case 'get_versions':
          $file = $input['file'] ?? '';
          $verDir = $baseDir . '/.file_version/' . basename($file);
          $versions = [];
          if (is_dir($verDir)) {
            foreach (array_diff(scandir($verDir), ['.', '..']) as $v) {
              $versions[] = ['name' => $v, 'mtime' => filemtime($verDir . '/' . $v), 'size' => formatBytes(filesize($verDir . '/' . $v))];
            }
            usort($versions, function($a, $b) { return $b['mtime'] - $a['mtime']; });
            $versions = array_slice($versions, 0, 25);
          }
          echo json_encode(['success' => true, 'versions' => $versions]);
          break;
        case 'restore_version':
          $file = $input['file'] ?? '';
          $versionName = $input['version_name'] ?? '';
          $src = $baseDir . '/.file_version/' . basename($file) . '/' . $versionName;
          $dest = $baseDir . '/' . $file;
          if (!file_exists($src)) throw new Exception('Version not found');
          save_file_version($dest);
          copy($src, $dest);
          echo json_encode(['success' => true]);
          break;
        case 'toggle_cli':
          $_SESSION['disable_cli'] = !empty($input['disable']);
          echo json_encode(['success' => true]);
          break;
        case 'terminal_cmd':
          if (!empty($_SESSION['disable_cli'])) {
            echo json_encode(['success' => false, 'output' => "CLI access has been disabled by the Administrator."]);
            break;
          }
          $cmd = trim($input['cmd'] ?? '');
          $path = rtrim($absPath ?? $baseDir, '/');

          if (preg_match('/[;&`\n$]/', $cmd) || strpos($cmd, '>') !== false || strpos($cmd, '<') !== false || preg_match('/(rm\s+-rf|curl|wget|nc|bash|sh|mkfifo|su|sudo)\b/i', $cmd)) {
            echo json_encode(['success' => false, 'output' => "Command restricted. Destructive and chaining commands are forbidden."]);
            break;
          }

          if (strpos($cmd, 'cd ') === 0) {
            echo json_encode(['success' => true, 'output' => "Directory changes are scoped to the UI explorer."]);
            break;
          }

          $lowerCmd = strtolower($cmd);
          if ($lowerCmd === 'php -v' || $lowerCmd === 'php --version') {
            echo json_encode(['success' => true, 'output' => "PHP " . PHP_VERSION . " (" . PHP_SAPI . ")\nCopyright (c) The PHP Group\nBuilt on " . PHP_OS]);
            break;
          }
          if ($lowerCmd === 'pwd') {
            echo json_encode(['success' => true, 'output' => $path]);
            break;
          }
          if ($lowerCmd === 'whoami') {
            echo json_encode(['success' => true, 'output' => $_SESSION['auth_role'] ?? 'user']);
            break;
          }
          if ($lowerCmd === 'date') {
            echo json_encode(['success' => true, 'output' => date('D M j H:i:s T Y')]);
            break;
          }
          if ($lowerCmd === 'ls' || $lowerCmd === 'dir' || strpos($lowerCmd, 'ls ') === 0) {
            $dirTarget = $path;
            if (strpos($lowerCmd, 'ls ') === 0) {
              $sub = trim(substr($cmd, 3));
              if ($sub && is_dir($path . '/' . $sub)) $dirTarget = $path . '/' . $sub;
            }
            $files = array_diff(scandir($dirTarget), ['.', '..']);
            $out = [];
            foreach ($files as $f) {
              $isD = is_dir($dirTarget . '/' . $f);
              $out[] = $isD ? $f . '/' : $f;
            }
            echo json_encode(['success' => true, 'output' => implode("\n", $out)]);
            break;
          }

          $output = "";
          $fullCmd = "cd " . escapeshellarg($path) . " && " . $cmd . " 2>&1";

          if (function_exists('proc_open')) {
            $descriptorspec = [
              0 => ["pipe", "r"],
              1 => ["pipe", "w"],
              2 => ["pipe", "w"]
            ];
            $process = @proc_open($fullCmd, $descriptorspec, $pipes);
            if (is_resource($process)) {
              @fclose($pipes[0]);
              $stdout = @stream_get_contents($pipes[1]);
              @fclose($pipes[1]);
              $stderr = @stream_get_contents($pipes[2]);
              @fclose($pipes[2]);
              @proc_close($process);
              $output = trim($stdout . "\n" . $stderr);
            }
          }

          if ($output === "" && function_exists('shell_exec')) {
            $output = @shell_exec($fullCmd);
          }
          if (empty($output) && function_exists('exec')) {
            @exec($fullCmd, $outArr);
            $output = implode("\n", $outArr);
          }
          if (empty($output) && function_exists('system')) {
            @system($fullCmd, $retval);
            if ($retval !== 0) $output = "Command failed with status " . $retval;
          }

          if ($output === null || $output === false || $output === "") {
            $output = "Command execution failed or shell functions are disabled on this host.";
          }

          echo json_encode(['success' => true, 'output' => htmlspecialchars(trim($output))]);
          break;
        case 'upload':
          $uploaded = 0;
          $chunk = isset($_POST['chunk']) ? (int)$_POST['chunk'] : 0;
          $chunks = isset($_POST['chunks']) ? (int)$_POST['chunks'] : 1;
          $fileId = $_POST['file_id'] ?? 'unknown';
          $override = !empty($_POST['override']);
          $paths = $_POST['paths'] ?? [];

          if (isset($_FILES['files'])) {
            foreach ($_FILES['files']['name'] as $i => $name) {
              $relPathClean = !empty($paths[$i]) ? ltrim(str_replace(['..', '\\'], ['', '/'], $paths[$i]), '/') : $name;
              $dest = $absPath . '/' . $relPathClean;
              $targetDir = dirname($dest);
              if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);

              if ($chunk === 0 && file_exists($dest) && !$override) {
                echo json_encode(['success' => false, 'error' => 'CONFLICT|' . basename($dest)]);
                exit;
              }

              if ($chunks > 1) {
                $tempDest = $targetDir . '/.temp_upload_' . md5($fileId . $name);
                $out = @fopen($tempDest, $chunk === 0 ? 'wb' : 'ab');
                if ($out) {
                  $in = @fopen($_FILES['files']['tmp_name'][$i], 'rb');
                  if ($in) { stream_copy_to_stream($in, $out); fclose($in); }
                  fclose($out);
                }
                if ($chunk == $chunks - 1) {
                  rename($tempDest, $dest);
                  $uploaded++;
                }
              } else {
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) $uploaded++;
              }
            }
          }
          echo json_encode(['success' => true, 'uploaded' => $uploaded]);
          break;
        default:
          throw new Exception('Unknown POST action');
      }
    } catch (Exception $e) {
      echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
  } else {
    try {
      switch ($action) {
        case 'search_drive':
          $q = strtolower($_GET['q'] ?? '');
          $folders = [];
          $files = [];
          if ($q !== '') {
            $dir = new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $filter = new RecursiveCallbackFilterIterator($dir, function ($current) {
              $exclude = ['.git', 'getid3', '.drive_trash_bin', '.drive_thumbnails', '.file_version', '.tmp_db', 'covers'];
              if ($current->isDir() && in_array($current->getFilename(), $exclude)) return false;
              return true;
            });
            $iter = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iter as $item) {
              $filename = $item->getFilename();
              if (stripos($filename, $q) !== false) {
                $rel = ltrim(str_replace($baseDir, '', $item->getPathname()), '/');
                $isDir = $item->isDir();
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $meta = [
                  'name' => $filename,
                  'path' => $rel,
                  'ext' => $ext,
                  'size' => $isDir ? 0 : $item->getSize(),
                  'formatSize' => $isDir ? '-' : formatBytes($item->getSize()),
                  'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])
                ];
                if ($isDir) $folders[] = $meta;
                else $files[] = $meta;
              }
            }
          }
          echo json_encode(['success' => true, 'folders' => array_slice($folders, 0, 50), 'files' => array_slice($files, 0, 100)]);
          break;
        case 'list':
          $reqPath = $_GET['path'] ?? '';
          $absPath = $reqPath ? $baseDir . '/' . $reqPath : $baseDir;
          if (!isValidPath($baseDir, $absPath) || !is_dir($absPath)) throw new Exception('Invalid path');
          $files = [];
          $folders = [];
          $items = array_diff(scandir($absPath), ['.', '..', '.git', '.file_version']);
          foreach ($items as $item) {
            $path = $absPath . '/' . $item;
            $rel = ltrim(str_replace($baseDir, '', $path), '/');
            $isDir = is_dir($path);
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            $meta = [
              'name' => $item,
              'path' => $rel,
              'ext' => $ext,
              'size' => $isDir ? 0 : filesize($path),
              'formatSize' => $isDir ? '-' : formatBytes(filesize($path)),
              'isImage' => in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])
            ];
            if ($isDir) $folders[] = $meta;
            else $files[] = $meta;
          }
          echo json_encode(['success' => true, 'folders' => $folders, 'files' => $files]);
          break;
        case 'properties':
          $fileParam = $_GET['file'] ?? '';
          $files = array_filter(explode('|', $fileParam));
          if (empty($files)) throw new Exception('Invalid item');

          if (count($files) === 1) {
            $file = $files[0];
            $full = $baseDir . '/' . $file;
            if (!isValidPath($baseDir, $full) || !file_exists($full)) throw new Exception('Invalid item');
            $stat = stat($full);
            $isDir = is_dir($full);
            $size = $stat['size'];
            $typeStr = $isDir ? 'Folder' : 'File (' . strtoupper(pathinfo($file, PATHINFO_EXTENSION)) . ')';
            $contentsStr = '';
            if ($isDir) {
              $totalFiles = 0; $totalFolders = 0; $totalSize = 0;
              $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
              foreach ($iter as $f) {
                if ($f->isDir()) $totalFolders++;
                else { $totalFiles++; $totalSize += $f->getSize(); }
              }
              $size = $totalSize;
              $contentsStr = $totalFiles . ' files, ' . $totalFolders . ' folders';
            }
            echo json_encode([
              'success' => true,
              'data' => [
                'name' => basename($file),
                'type' => $typeStr,
                'size' => formatBytes($size),
                'contents' => $contentsStr,
                'modified' => date("Y-m-d H:i:s", $stat['mtime']),
                'created' => date("Y-m-d H:i:s", $stat['ctime']),
                'permissions' => substr(sprintf('%o', fileperms($full)), -4)
              ]
            ]);
          } else {
            $totalSize = 0;
            $totalFiles = 0;
            $totalFolders = 0;
            foreach ($files as $f) {
              $full = $baseDir . '/' . $f;
              if (isValidPath($baseDir, $full) && file_exists($full)) {
                if (is_dir($full)) {
                  $totalFolders++;
                  $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                  foreach ($iter as $item) {
                    if ($item->isDir()) $totalFolders++;
                    else { $totalFiles++; $totalSize += $item->getSize(); }
                  }
                } else {
                  $totalFiles++;
                  $totalSize += filesize($full);
                }
              }
            }
            echo json_encode([
              'success' => true,
              'data' => [
                'name' => count($files) . ' items selected',
                'type' => 'Multiple Selection',
                'size' => formatBytes($totalSize),
                'contents' => $totalFiles . ' files, ' . $totalFolders . ' folders',
                'modified' => '-',
                'created' => '-',
                'permissions' => '-'
              ]
            ]);
          }
          break;
        case 'read':
          $file = $_GET['file'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full) || !is_file($full)) throw new Exception('Invalid file');
          echo json_encode(['success' => true, 'content' => file_get_contents($full)], JSON_INVALID_UTF8_SUBSTITUTE);
          break;
        case 'stream':
          $file = $_GET['file'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full) || !is_file($full)) {
            http_response_code(404);
            exit;
          }
          streamFileRange($full);
          break;
        case 'download':
          $fileParam = $_GET['file'] ?? '';
          $files = array_filter(explode('|', $fileParam));
          if (empty($files)) {
            http_response_code(400);
            exit;
          }

          if (count($files) === 1) {
            $full = $baseDir . '/' . $files[0];
            if (!isValidPath($baseDir, $full) || !file_exists($full)) {
              http_response_code(404);
              exit;
            }
            if (is_dir($full)) {
              $zipName = basename($full) . '.zip';
              header('Content-Type: application/zip');
              header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
              $zip = new ZipArchive();
              $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
              if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $f) {
                  if ($f->isFile()) $zip->addFile($f->getPathname(), basename($full) . '/' . str_replace($full . '/', '', $f->getPathname()));
                }
                $zip->close();
                readfile($tmpZip);
                @unlink($tmpZip);
              }
            } else {
              header('Content-Description: File Transfer');
              header('Content-Type: application/octet-stream');
              header('Content-Disposition: attachment; filename="' . rawurlencode(basename($full)) . '"');
              header('Content-Length: ' . filesize($full));
              readfile($full);
            }
          } else {
            $zipName = 'Download_' . date('Ymd_His') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
            $zip = new ZipArchive();
            $tmpZip = tempnam(sys_get_temp_dir(), 'zip');
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
              foreach ($files as $item) {
                $src = $baseDir . '/' . $item;
                if (!isValidPath($baseDir, $src) || !file_exists($src)) continue;
                if (is_file($src)) {
                  $zip->addFile($src, basename($src));
                } elseif (is_dir($src)) {
                  $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
                  foreach ($iter as $f) {
                    if ($f->isFile()) $zip->addFile($f->getPathname(), basename($src) . '/' . str_replace($src . '/', '', $f->getPathname()));
                  }
                }
              }
              $zip->close();
              readfile($tmpZip);
              @unlink($tmpZip);
            }
          }
          exit;
        default:
          throw new Exception('Unknown GET action');
      }
    } catch (Exception $e) {
      echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=1024" />
    <title>PHPEditor</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,%3Csvg%20width=%2224%22%20height=%2224%22%20viewBox=%220%200%2024%2024%22%20fill=%22none%22%20xmlns=%22http://www.w3.org/2000/svg%22%3E%3Crect%20width=%2224%22%20height=%2224%22%20rx=%226%22%20fill=%22%230a0a0a%22/%3E%3Cpath%20d=%22M4%2010V13%22%20stroke=%22%23ffffff%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3Cpath%20d=%22M16%2010V13%22%20stroke=%22%23ffffff%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3Cpath%20d=%22M7%207L7%2016%22%20stroke=%22%23ff0044%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3Cpath%20d=%22M13%207L13%2016%22%20stroke=%22%23ffffff%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3Cpath%20d=%22M19%207L19%2016%22%20stroke=%22%23ffffff%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3Cpath%20d=%22M10%204L10%2019%22%20stroke=%22%23ffffff%22%20stroke-width=%221.7%22%20stroke-linecap=%22round%22/%3E%3C/svg%3E" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ace.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ext-searchbox.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ext-modelist.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.36.2/ext-language_tools.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/diff_match_patch/20121119/diff_match_patch.js"></script>
    <style>
      body {
        background-color: #030303;
        color: #ffffff;
        font-family: "Roboto", sans-serif;
        margin: 0;
        height: 100vh;
        overflow: hidden;
      }

      ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
      }
      ::-webkit-scrollbar-track {
        background: var(--ytm-surface);
      }
      ::-webkit-scrollbar-thumb {
        background: var(--ytm-surface-2);
        border-radius: 4px;
      }
      ::-webkit-scrollbar-thumb:hover {
        background: #555;
      }

      .ide-container {
        padding: 0 !important;
        background-color: #0a0a0a;
        display: flex;
        flex-direction: column;
        height: 100%;
        width: 100%;
      }

      .ide-header {
        height: 48px;
        background-color: #121212;
        border-bottom: 1px solid #2d2d2d;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 1rem;
        flex-shrink: 0;
      }

      .ide-header-title {
        color: #e3e3e3;
        font-weight: bold;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .ide-header-title span {
        background: #262626;
        color: #aaaaaa;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: normal;
        margin-left: 8px;
      }

      .ide-actions {
        display: flex;
        gap: 8px;
      }

      .ide-btn {
        background: transparent;
        border: 1px solid #555555;
        color: #e3e3e3;
        border-radius: 4px;
        padding: 4px 12px;
        font-size: 0.85rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: 0.2s;
      }

      .ide-btn:hover {
        background: #262626;
        color: #ffffff;
      }

      .ide-body {
        display: flex;
        flex: 1;
        min-height: 0;
        overflow: hidden;
      }

      .ide-activity-bar {
        width: 50px;
        background-color: #000000;
        border-right: 1px solid #1a1a1a;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding-top: 12px;
        flex-shrink: 0;
        z-index: 10;
      }

      .ide-activity-action {
        width: 38px;
        height: 38px;
        display: flex;
        justify-content: center;
        align-items: center;
        color: #888;
        font-size: 1.3rem;
        cursor: pointer;
        border-radius: 8px;
        margin-bottom: 8px;
        transition: 0.2s;
      }

      .ide-activity-action.active,
      .ide-activity-action:hover {
        color: #ff0000;
        background: rgba(255, 0, 0, 0.1);
      }

      .ide-sidebar {
        width: 200px;
        background-color: #0a0a0a;
        border-right: 1px solid #2d2d2d;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        position: relative;
      }

      .ide-sidebar-header {
        padding: 12px;
        color: #ffffff;
        font-size: 0.75rem;
        font-weight: bold;
        letter-spacing: 1px;
        border-bottom: 1px solid #1a1a1a;
        display: flex;
        justify-content: space-between;
      }

      .ide-file-tree {
        flex: 1;
        overflow-y: auto;
        padding: 8px;
        font-size: 0.85rem;
        user-select: none;
        -webkit-user-select: none;
      }

      .ide-tree-item {
        padding: 4px 8px;
        color: #aaaaaa;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        border-radius: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .ide-tree-item:hover {
        background-color: #1a1a1a;
        color: #ffffff;
      }

      .ide-tree-item.active,
      .ide-tree-item.selected {
        background-color: rgba(255, 0, 0, 0.2);
        color: #ffffff;
        border-left: 3px solid #ff0000;
        border-radius: 0 4px 4px 0;
      }

      .ide-editor-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
        position: relative;
      }

      .ide-tabs {
        display: flex;
        background: #0a0a0a;
        border-bottom: 1px solid #2d2d2d;
        overflow-x: auto;
        flex-shrink: 0;
      }

      .ide-tabs::-webkit-scrollbar {
        height: 4px;
      }

      .ide-tabs::-webkit-scrollbar-thumb {
        background: #555555;
      }

      .ide-tab {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #0a0a0a;
        border-right: 1px solid #2d2d2d;
        color: #aaaaaa;
        font-size: 0.85rem;
        border-top: 2px solid transparent;
        cursor: pointer;
        white-space: nowrap;
      }

      .ide-tab.active {
        background: #121212;
        color: #ffffff;
        border-top: 2px solid #ff0000;
      }

      .ide-tab:hover:not(.active) {
        background: #1a1a1a;
        color: #e3e3e3;
      }

      .ide-tab-close {
        opacity: 0.5;
        transition: 0.2s;
        padding: 2px;
        border-radius: 4px;
      }

      .ide-tab-close:hover {
        opacity: 1;
        background: rgba(255, 0, 0, 0.2);
        color: #ff0000;
      }

      .ide-editor-container {
        flex: 1;
        position: relative;
        background: #121212;
        display: flex;
        flex-direction: column;
      }

      .ide-status-bar {
        height: 24px;
        background-color: #1e1e1e;
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 10px;
        font-size: 0.75rem;
        flex-shrink: 0;
        z-index: 10;
      }

      .ide-status-left,
      .ide-status-right {
        display: flex;
        align-items: center;
        gap: 12px;
      }

      .ide-bottom-panel {
        display: none;
        flex-direction: column;
        background: #0a0a0a;
        height: 250px;
        flex-shrink: 0;
        position: relative;
        border-top: 1px solid #2d2d2d;
      }

      .ide-bottom-panel.active {
        display: flex;
      }

      .ide-bottom-panel.fullscreen {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        height: auto !important;
        z-index: 100;
        border-left: none;
        border-top: none;
      }

      .ide-panel-resizer {
        height: 4px;
        background: #2d2d2d;
        cursor: ns-resize;
        width: 100%;
        transition: background 0.2s;
        flex-shrink: 0;
        z-index: 10;
      }

      .ide-panel-resizer:hover,
      .ide-panel-resizer.resizing {
        background: #ff0000;
      }

      .panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #121212;
        border-bottom: 1px solid #2d2d2d;
        padding: 0 16px;
        height: 35px;
        flex-shrink: 0;
      }

      .panel-tabs {
        display: flex;
        height: 100%;
        gap: 16px;
      }

      .panel-tab {
        color: #aaaaaa;
        font-size: 0.8rem;
        text-transform: uppercase;
        cursor: pointer;
        display: flex;
        align-items: center;
        border-bottom: 2px solid transparent;
      }

      .panel-tab.active {
        color: #ffffff;
        border-bottom-color: #ff0000;
        font-weight: bold;
      }

      .panel-tab:hover:not(.active) {
        color: #e3e3e3;
      }

      .panel-actions {
        display: flex;
        gap: 12px;
        color: #aaaaaa;
      }

      .panel-actions i {
        cursor: pointer;
        transition: 0.2s;
        font-size: 0.9rem;
      }

      .panel-actions i:hover {
        color: #ffffff;
      }

      .panel-content-area {
        flex: 1;
        overflow: auto;
        background: #0a0a0a;
        color: #e3e3e3;
        font-family: monospace;
        font-size: 0.85rem;
        padding: 12px;
        position: relative;
      }

      .panel-pane {
        display: none;
        height: 100%;
        width: 100%;
      }

      .panel-pane.active {
        display: block;
      }

      .ide-ctx-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #121212;
        border: 1px solid #2d2d2d;
        border-radius: 12px;
        width: 320px;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.8);
        z-index: 5000;
        display: none;
        flex-direction: column;
        padding: 12px;
      }

      .ide-ctx-title {
        color: #aaaaaa;
        font-size: 0.85rem;
        padding: 8px 12px;
        font-weight: bold;
        border-bottom: 1px solid #2d2d2d;
        margin-bottom: 8px;
        word-break: break-all;
      }

      .ide-ctx-btn {
        background: transparent;
        border: none;
        color: #e3e3e3;
        text-align: left;
        padding: 10px 12px;
        border-radius: 6px;
        cursor: pointer;
        transition: 0.2s;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.9rem;
        width: 100%;
      }

      .ide-ctx-btn:hover {
        background: #1a1a1a;
        color: #ffffff;
      }

      .ide-ctx-btn.text-danger:hover {
        background: rgba(255, 0, 0, 0.1);
      }

      .ide-sidebar-resizer {
        position: absolute;
        top: 0;
        right: 0;
        width: 4px;
        height: 100%;
        cursor: col-resize;
        background: transparent;
        z-index: 10;
        transition: background 0.2s;
      }

      .ide-sidebar-resizer:hover,
      .ide-sidebar-resizer.resizing {
        background: #ff0000;
      }
    </style>
  </head>

  <body>
    <div class="ide-container">
      <div class="ide-header">
        <div class="ide-header-title">
          PHPEditor
          <span id="ide-current-file">No file selected</span>
          <?php if ($isDemo): ?>
            <span class="badge bg-warning text-dark ms-2">Demo Mode (Read-Only)</span>
          <?php else: ?>
            <span class="badge bg-success ms-2">Admin</span>
          <?php endif; ?>
        </div>
        <div class="ide-actions">
          <button class="ide-btn" id="ide-fullscreen-btn" title="Toggle Fullscreen IDE">
            <i class="bi bi-arrows-fullscreen"></i> Fullscreen
          </button>
          <button class="ide-btn" id="ide-find-btn" title="Ctrl+F">
            <i class="bi bi-search"></i> Find
          </button>
          <button class="ide-btn" id="ide-save-btn" title="Ctrl+S">
            <i class="bi bi-floppy"></i> Save
          </button>
          <button class="ide-btn" id="ide-preview-btn">
            <i class="bi bi-play-fill"></i> Execute / Preview
          </button>
          <a href="?action=logout" class="ide-btn text-danger" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
          </a>
        </div>
      </div>

      <div class="ide-ctx-modal" id="ide-ctx-modal">
        <div class="ide-ctx-title" id="ide-ctx-title">file.php</div>
        <input type="hidden" id="ide-ctx-path" />
        <input type="hidden" id="ide-ctx-is-folder" />
        <button class="ide-ctx-btn" id="ide-btn-new-file">
          <i class="bi bi-file-earmark-plus"></i> New file here
        </button>
        <button class="ide-ctx-btn" id="ide-btn-new-folder">
          <i class="bi bi-folder-plus"></i> New folder here
        </button>
        <button class="ide-ctx-btn" id="ide-btn-rename">
          <i class="bi bi-pencil-square"></i> Rename
        </button>
        <button class="ide-ctx-btn" id="ide-btn-copy"><i class="bi bi-copy"></i> Copy</button>
        <button class="ide-ctx-btn" id="ide-btn-cut"><i class="bi bi-scissors"></i> Cut</button>
        <button class="ide-ctx-btn" id="ide-btn-paste" style="display: none;"><i class="bi bi-clipboard"></i> Paste Here</button>
        <button class="ide-ctx-btn" id="ide-btn-download">
          <i class="bi bi-download"></i> Download
        </button>
        <button class="ide-ctx-btn" id="ide-btn-properties">
          <i class="bi bi-info-circle"></i> Properties
        </button>
        <button class="ide-ctx-btn" id="ide-btn-zip">
          <i class="bi bi-file-zip"></i> Zip Items
        </button>
        <button class="ide-ctx-btn" id="ide-btn-unzip">
          <i class="bi bi-file-zip"></i> Extract Zip
        </button>
        <button class="ide-ctx-btn text-danger" id="ide-btn-delete">
          <i class="bi bi-trash"></i> Delete
        </button>
        <hr class="border-secondary my-2 opacity-25" />
        <button class="ide-ctx-btn" id="ide-btn-upload">
          <i class="bi bi-upload"></i> Upload Files
        </button>
        <button class="ide-ctx-btn" id="ide-btn-refresh">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <hr class="border-secondary my-2 opacity-25" />
        <button class="ide-ctx-btn justify-content-center text-secondary fw-bold" onclick="document.getElementById('ide-ctx-modal').style.display='none'">
          Cancel
        </button>
      </div>

      <div class="modal fade" id="ide-settings-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content border-danger shadow-lg" style="background-color: #0a0a0a;">
            <div class="modal-header border-bottom border-danger">
              <h5 class="modal-title text-white fw-bold"><i class="bi bi-gear-fill text-danger me-2"></i>IDE Settings</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-white" style="font-size: 0.85rem;">
              <div class="mb-3">
                <label class="form-label fw-bold text-danger mb-1">THEME</label>
                <select id="ide-setting-theme" class="form-select form-select-sm bg-dark text-white border-secondary">
                  <optgroup label="Dark Themes">
                    <option value="ace/theme/ambiance">Ambiance</option>
                    <option value="ace/theme/chaos">Chaos</option>
                    <option value="ace/theme/clouds_midnight">Clouds Midnight</option>
                    <option value="ace/theme/cobalt">Cobalt</option>
                    <option value="ace/theme/colorforth">Colorforth</option>
                    <option value="ace/theme/dracula">Dracula</option>
                    <option value="ace/theme/gob">Gob</option>
                    <option value="ace/theme/gruvbox">Gruvbox</option>
                    <option value="ace/theme/idle_fingers">idle Fingers</option>
                    <option value="ace/theme/kr_theme">krTheme</option>
                    <option value="ace/theme/merbivore">Merbivore</option>
                    <option value="ace/theme/merbivore_soft">Merbivore Soft</option>
                    <option value="ace/theme/mono_industrial">Mono Industrial</option>
                    <option value="ace/theme/monokai">Monokai</option>
                    <option value="ace/theme/nord_dark">Nord Dark</option>
                    <option value="ace/theme/one_dark">One Dark</option>
                    <option value="ace/theme/pastel_on_dark">Pastel on dark</option>
                    <option value="ace/theme/solarized_dark">Solarized Dark</option>
                    <option value="ace/theme/terminal">Terminal</option>
                    <option value="ace/theme/tomorrow_night">Tomorrow Night</option>
                    <option value="ace/theme/tomorrow_night_blue">Tomorrow Night Blue</option>
                    <option value="ace/theme/tomorrow_night_bright">Tomorrow Night Bright</option>
                    <option value="ace/theme/tomorrow_night_eighties" selected>Tomorrow Night 80s</option>
                    <option value="ace/theme/twilight">Twilight</option>
                    <option value="ace/theme/vibrant_ink">Vibrant Ink</option>
                    <option value="ace/theme/github_dark">GitHub Dark</option>
                  </optgroup>
                  <optgroup label="Light Themes">
                    <option value="ace/theme/chrome">Chrome</option>
                    <option value="ace/theme/clouds">Clouds</option>
                    <option value="ace/theme/crimson_editor">Crimson Editor</option>
                    <option value="ace/theme/dawn">Dawn</option>
                    <option value="ace/theme/dreamweaver">Dreamweaver</option>
                    <option value="ace/theme/eclipse">Eclipse</option>
                    <option value="ace/theme/github">GitHub</option>
                    <option value="ace/theme/iplastic">IPlastic</option>
                    <option value="ace/theme/solarized_light">Solarized Light</option>
                    <option value="ace/theme/textmate">TextMate</option>
                    <option value="ace/theme/tomorrow">Tomorrow</option>
                    <option value="ace/theme/xcode">Xcode</option>
                    <option value="ace/theme/kuroir">Kuroir</option>
                    <option value="ace/theme/katzenmilch">KatzenMilch</option>
                    <option value="ace/theme/sqlserver">SQL Server</option>
                  </optgroup>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label fw-bold text-danger mb-1">INDENTATION</label>
                <select id="ide-setting-indent" class="form-select form-select-sm bg-dark text-white border-secondary">
                  <option value="2">2 Spaces</option>
                  <option value="4">4 Spaces</option>
                  <option value="tab">Tabs</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label d-flex justify-content-between align-items-center fw-bold text-danger mb-1">
                  <span>FONT SIZE</span>
                  <span id="ide-setting-fontsize-val" class="text-white">14px</span>
                </label>
                <div class="d-flex align-items-center gap-2">
                  <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2 py-0 fw-bold" id="ide-fontsize-minus" style="height: 28px; min-width: 32px;">-</button>
                  <input type="range" class="form-range flex-grow-1" id="ide-setting-fontsize" min="10" max="36" step="1" value="14">
                  <button type="button" class="btn btn-sm btn-outline-danger border-0 px-2 py-0 fw-bold" id="ide-fontsize-plus" style="height: 28px; min-width: 32px;">+</button>
                </div>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input bg-dark border-secondary" type="checkbox" id="ide-setting-wrap">
                <label class="form-check-label">Word Wrap</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input bg-dark border-secondary" type="checkbox" id="ide-setting-autosave">
                <label class="form-check-label">Auto Save (Every 10s)</label>
              </div>
              <div class="form-check form-switch mb-2">
                <input class="form-check-input bg-dark border-secondary" type="checkbox" id="ide-setting-show_wordcount">
                <label class="form-check-label">Show Word Count</label>
              </div>
              <div class="form-check form-switch mb-3">
                <input class="form-check-input bg-dark border-secondary" type="checkbox" id="ide-setting-show_charcount">
                <label class="form-check-label">Show Character Count</label>
              </div>
              <hr class="border-danger opacity-50">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input bg-dark border-danger" type="checkbox" id="ide-setting-disable-cli">
                <label class="form-check-label text-danger fw-bold">Disable Terminal / CLI</label>
              </div>
            </div>
            <div class="modal-footer border-top border-danger">
              <button class="btn btn-danger w-100 fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="ide-rename-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <div class="modal-content border-danger shadow-lg" style="background-color: #0a0a0a;">
            <div class="modal-header border-bottom border-danger">
              <h5 class="modal-title text-white fw-bold"><i class="bi bi-pencil-square text-danger me-2"></i>Rename</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-white">
              <div class="mb-3">
                <label class="form-label text-danger fw-bold small">NEW NAME</label>
                <input type="text" id="ide-rename-input" class="form-control bg-dark text-white border-secondary">
              </div>
            </div>
            <div class="modal-footer border-top border-danger">
              <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-danger btn-sm fw-bold" id="ide-rename-submit">Rename</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="ide-diff-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
          <div class="modal-content border-secondary shadow-lg" style="background-color: #0a0a0a;">
            <div class="modal-header border-bottom border-secondary">
              <h5 class="modal-title text-white fw-bold"><i class="bi bi-file-diff text-info me-2"></i><span id="ide-diff-title">File Diff</span></h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-white font-monospace p-3" id="ide-diff-body" style="font-size: 0.85rem; line-height: 1.6; white-space: pre-wrap; word-break: break-all; background-color: #121212; min-height: 300px;">
              <div class="text-center py-5"><div class="spinner-border text-info"></div></div>
            </div>
            <div class="modal-footer border-top border-secondary py-2">
              <div class="d-flex align-items-center gap-3 me-auto small font-monospace">
                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2 py-1"><del style="text-decoration:none;">Deleted / Old</del></span>
                <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-1"><ins style="text-decoration:none;">Added / New</ins></span>
              </div>
              <button type="button" class="btn btn-outline-light btn-sm fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="ide-properties-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content border-danger shadow-lg" style="background-color: #0a0a0a;">
            <div class="modal-header border-bottom border-danger">
              <h5 class="modal-title text-white fw-bold"><i class="bi bi-info-circle-fill text-danger me-2"></i>Item Properties</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-white" id="ide-properties-body">
              <div class="text-center py-3"><div class="spinner-border text-danger"></div></div>
            </div>
            <div class="modal-footer border-top border-danger">
              <button type="button" class="btn btn-danger btn-sm fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>

      <div class="ide-body">
        <div class="ide-activity-bar">
          <div class="ide-activity-action active" id="act-explorer" title="Files / Explorer" onclick="window.switchIdeSidebar('explorer')">
            <i class="bi bi-files"></i>
          </div>
          <div class="ide-activity-action" id="act-history" title="File History" onclick="window.switchIdeSidebar('history')">
            <i class="bi bi-clock-history"></i>
          </div>
          <div class="ide-activity-action" id="act-settings" title="Settings">
            <i class="bi bi-gear"></i>
          </div>
          <div class="ide-activity-action" title="Terminal" onclick="window.toggleIdeTerminal()">
            <i class="bi bi-terminal"></i>
          </div>
        </div>

        <div class="ide-sidebar" id="ide-main-sidebar">
          <div class="ide-sidebar-resizer" id="ide-sidebar-resizer"></div>
          <div class="ide-sidebar-header d-flex justify-content-between align-items-center" id="ide-sidebar-title">
            <span>EXPLORER</span>
            <div class="d-flex gap-2">
              <i class="bi bi-search" style="cursor: pointer" id="ide-tree-search" title="Search Files"></i>
              <i class="bi bi-file-earmark-plus" style="cursor: pointer" id="ide-tree-new-file" title="New File"></i>
              <i class="bi bi-folder-plus" style="cursor: pointer" id="ide-tree-new-folder" title="New Folder"></i>
              <i class="bi bi-upload" style="cursor: pointer" id="ide-tree-upload" title="Upload"></i>
              <i class="bi bi-arrow-clockwise" style="cursor: pointer" id="ide-refresh-tree" title="Refresh"></i>
            </div>
          </div>
          
          <div id="ide-sidebar-search-container" class="p-2 d-none border-bottom" style="border-color: #1a1a1a;">
            <input type="text" id="ide-sidebar-search-input" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Search workspace...">
          </div>

          <div id="ide-sidebar-clipboard-container" class="p-2 d-none border-bottom" style="border-color: #1a1a1a; background-color: rgba(255, 0, 0, 0.05);">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="text-danger fw-bold" style="font-size: 0.75rem; text-transform: uppercase;" id="ide-clipboard-status">0 items copied</span>
              <button class="btn btn-sm btn-link text-secondary py-0 px-2 m-0" id="ide-btn-cancel-clipboard"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-secondary w-100 fw-bold" id="ide-btn-clipboard-new-folder" title="New Folder Here"><i class="bi bi-folder-plus"></i> Folder</button>
              <button class="btn btn-sm btn-danger w-100 fw-bold" id="ide-btn-clipboard-paste" title="Paste Here"><i class="bi bi-clipboard"></i> Paste</button>
            </div>
          </div>

          <div class="ide-file-tree" id="ide-file-tree">
            <div class="text-center mt-4 text-secondary">
              <i class="spinner-border spinner-border-sm"></i> Loading...
            </div>
          </div>

          <div class="ide-file-tree d-none" id="ide-history-tree" style="padding: 12px; font-family: monospace; font-size: 0.8rem; color: #ccc;">
            <div class="text-center mt-4 text-secondary">Select a file in Explorer to view its history.</div>
          </div>
        </div>

        <div class="ide-editor-wrapper">
          <div class="ide-tabs" id="ide-tabs-container"></div>
          <div class="ide-editor-container">
            <div style="flex: 1; position: relative">
              <div id="ide-editor" class="position-absolute w-100 h-100"></div>
              <div id="ide-media-viewer" class="position-absolute w-100 h-100 d-none flex-column align-items-center justify-content-center bg-dark">
                <div id="ide-media-content" class="shadow-lg rounded" style="max-width: 90%; max-height: 90%"></div>
                <div id="ide-media-info" class="mt-3 text-secondary font-monospace small"></div>
              </div>
              <div id="ide-empty-state" class="position-absolute w-100 h-100 d-flex flex-column align-items-center justify-content-center text-secondary">
                <i class="bi bi-code-slash mb-3" style="font-size: 4rem; opacity: 0.3"></i>
                <h5>PHP Editor</h5>
                <p class="small">Select a file from the explorer to begin.</p>
              </div>
            </div>
            <div class="ide-status-bar" id="ide-status-bar" style="display: none">
              <div class="ide-status-left">
                <span id="ide-status-file-size">0 KB</span>
                <span id="ide-status-word-count" style="display: none">0 words</span>
                <span id="ide-status-char-count" style="display: none">0 chars</span>
              </div>
              <div class="ide-status-right">
                <span id="ide-status-cursor">Ln 1, Col 1</span>
                <span class="ide-status-item" id="ide-status-indent">Spaces: 2</span>
              </div>
            </div>
          </div>
          
          <div class="ide-bottom-panel" id="ide-bottom-panel">
            <div class="ide-panel-resizer" id="ide-panel-resizer"></div>
            <div class="panel-header">
              <div class="panel-tabs">
                <div class="panel-tab active" data-target="output">PREVIEW</div>
                <div class="panel-tab" data-target="terminal">TERMINAL</div>
              </div>
              <div class="panel-actions d-flex align-items-center gap-3">
                <i class="bi bi-arrow-clockwise" id="panel-reload" title="Reload Preview"></i>
                <i class="bi bi-bug" id="panel-eruda" title="Toggle Eruda Inspect"></i>
                <i class="bi bi-box-arrow-up-right" id="panel-newtab" title="Open in New Tab"></i>
                <i class="bi bi-x-circle" id="panel-clear" title="Clear Console"></i>
                <i class="bi bi-chevron-up" id="panel-fullscreen" title="Toggle Size"></i>
                <i class="bi bi-x" id="panel-close" title="Close Panel"></i>
              </div>
            </div>
            <div class="panel-content-area">
              <div class="panel-pane active" id="pane-output">
                <iframe id="ide-preview-iframe" class="w-100 h-100 border-0 bg-white" src="about:blank"></iframe>
              </div>
              <div class="panel-pane" id="pane-terminal">
                <div class="text-success">PHP Console</div>
                <div id="terminal-logs" class="mt-2" style="white-space: pre-wrap; font-family: monospace"></div>
                <div class="d-flex align-items-center mt-2">
                  <span class="text-success me-2">~$</span>
                  <input type="text" id="ide-terminal-input" class="bg-transparent border-0 text-light flex-grow-1 shadow-none" style="outline: none; font-family: monospace" placeholder="Type command..." />
                </div>
              </div>
            </div>
          </div>
          
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      (function initIDE() {
        const editorDiv = document.getElementById('ide-editor');
        if (!editorDiv) return;

        const mediaViewer = document.getElementById('ide-media-viewer');
        const mediaContent = document.getElementById('ide-media-content');
        const emptyState = document.getElementById('ide-empty-state');

        const aceEditor = ace.edit(editorDiv);
        const savedTheme = localStorage.getItem('ide_theme') || "ace/theme/chaos";
        const savedIndent = localStorage.getItem('ide_indent') || "2";
        const savedWrap = localStorage.getItem('ide_wrap') === 'true';
        const savedFontSize = localStorage.getItem('ide_fontsize') || "14";

        aceEditor.setTheme(savedTheme);
        aceEditor.session.setMode("ace/mode/php");
        aceEditor.session.setTabSize(savedIndent === 'tab' ? 4 : parseInt(savedIndent));
        aceEditor.session.setUseSoftTabs(savedIndent !== 'tab');
        aceEditor.setOptions({
          fontSize: savedFontSize + "px",
          showPrintMargin: false,
          enableBasicAutocompletion: true,
          wrap: savedWrap
        });

        const updateIDEStatusBar = () => {
          const pos = aceEditor.getCursorPosition();
          document.getElementById('ide-status-cursor').innerText = `Ln ${pos.row + 1}, Col ${pos.column + 1}`;
          document.getElementById('ide-status-indent').innerText = savedIndent === 'tab' ? 'Tabs' : `Spaces: ${savedIndent}`;

          const file = openFiles.find(f => f.path === currentPath);
          const isEditorActive = editorDiv.style.display !== 'none';

          const wordEl = document.getElementById('ide-status-word-count');
          const charEl = document.getElementById('ide-status-char-count');

          if (!isEditorActive) {
            wordEl.style.display = 'none';
            charEl.style.display = 'none';
            if (file) {
              document.getElementById('ide-status-file-size').innerText = file.formatSize || 'Unknown';
            }
          } else {
            wordEl.style.display = localStorage.getItem('ide_show_wordcount') === 'true' ? 'inline' : 'none';
            charEl.style.display = localStorage.getItem('ide_show_charcount') === 'true' ? 'inline' : 'none';

            const val = aceEditor.getValue();
            const byteSize = new Blob([val]).size;
            let displaySize = '';
            if (byteSize < 1024) displaySize = byteSize + ' B';
            else if (byteSize < 1024 * 1024) displaySize = (byteSize / 1024).toFixed(2) + ' KB';
            else displaySize = (byteSize / (1024 * 1024)).toFixed(2) + ' MB';
            document.getElementById('ide-status-file-size').innerText = displaySize;

            const charCount = val.length;
            let wordCount = 0;
            if (charCount > 1000000) {
              wordCount = '~' + Math.round(charCount / 6);
            } else {
              wordCount = val.trim() ? val.trim().split(/\s+/).length : 0;
            }
            wordEl.innerText = `${wordCount} words`;
            charEl.innerText = `${charCount} chars`;
          }
        };

        aceEditor.session.selection.on('changeCursor', updateIDEStatusBar);
        aceEditor.session.on('change', updateIDEStatusBar);

        aceEditor.commands.addCommand({
          name: 'save',
          bindKey: {
            win: 'Ctrl-S',
            mac: 'Cmd-S'
          },
          exec: function() {
            if (typeof window.saveCurrentFile === 'function') window.saveCurrentFile();
          }
        });

        if (typeof ResizeObserver !== 'undefined') {
          new ResizeObserver(() => aceEditor.resize(true)).observe(editorDiv);
        }

        const treeEl = document.getElementById('ide-file-tree');
        const currentFileEl = document.getElementById('ide-current-file');
        const tabsContainer = document.getElementById('ide-tabs-container');
        const bottomPanel = document.getElementById('ide-bottom-panel');
        const previewIframe = document.getElementById('ide-preview-iframe');

        let currentPath = '';
        let openFiles = JSON.parse(localStorage.getItem('ide_open_files') || '[]');
        let activeTabPath = localStorage.getItem('ide_active_tab') || '';
        const mediaExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mp3', 'wav', 'ogg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'odp', 'csv'];

        window.ideSelectedItems = new Set();
        let isIdeSelecting = false;
        let ideSelectionBox = null;
        let ideSelectStartX = 0, ideSelectStartY = 0;
        let baseIdeSelected = new Set();

        window.updateIdeSelectionUI = () => {
          if (treeEl) {
            treeEl.querySelectorAll('.ide-tree-item').forEach(el => {
              el.style.backgroundColor = '';
              el.style.color = '';
              if (window.ideSelectedItems.has(el.dataset.path)) {
                el.classList.add('selected');
              } else {
                el.classList.remove('selected');
              }
            });
          }
        };

        const termLog = (msg, isError = false) => {
          const logs = document.getElementById('terminal-logs');
          if (logs) {
            logs.innerHTML += `<div class="${isError ? 'text-danger' : 'text-light'}">${msg}</div>`;
            logs.scrollTop = logs.scrollHeight;
          }
        };

        const driveFetch = async (action, body = null, reqPath = '') => {
          try {
            if (body && !(body instanceof FormData)) {
              body.csrf_token = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
            }
            const res = await fetch(`?api=true&action=${action}&path=${encodeURIComponent(reqPath)}`, {
              method: 'POST',
              headers: (body instanceof FormData) ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'},
              body: (body instanceof FormData) ? body : JSON.stringify(body)
            });
            if (!res.ok) throw new Error(`HTTP Error ${res.status}`);
            return await res.json();
          } catch (e) {
            termLog(`API Error (${action}): ${e.message}`, true);
            return { success: false, error: e.message };
          }
        };

        window.ideChunkedUpload = async (filesList, pathsList, targetPath = '') => {
          if (filesList.length === 0) return;
          termLog(`Starting chunked upload for ${filesList.length} file(s)...`);
          const csrfToken = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
          let totalUploaded = 0;

          for (let i = 0; i < filesList.length; i++) {
            const file = filesList[i];
            const chunkSize = 5 * 1024 * 1024;
            const totalChunks = Math.ceil(file.size / chunkSize) || 1;
            const fileId = 'ide_up_' + Math.random().toString(36).substring(2, 9);
            const rawRelPath = pathsList[i] || file.name;
            const fullPath = targetPath ? (targetPath + '/' + rawRelPath) : rawRelPath;
            
            let success = true;
            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
              const start = chunkIndex * chunkSize;
              const end = Math.min(start + chunkSize, file.size);
              const chunkBlob = file.slice(start, end);
              
              const fd = new FormData();
              fd.append('action', 'upload');
              fd.append('csrf_token', csrfToken);
              fd.append('files[]', chunkBlob, file.name);
              fd.append('paths[]', fullPath);
              fd.append('chunk', chunkIndex);
              fd.append('chunks', totalChunks);
              fd.append('file_id', fileId);

              try {
                const res = await fetch(`?api=true`, { method: 'POST', body: fd }).then(r => r.json());
                if (!res.success && res.error && res.error.startsWith('CONFLICT|')) {
                  fd.append('override', '1');
                  const resRetry = await fetch(`?api=true`, { method: 'POST', body: fd }).then(r => r.json());
                  if (!resRetry.success) throw new Error(resRetry.error);
                } else if (!res.success) {
                  throw new Error(res.error);
                }
                const progress = Math.round(((chunkIndex + 1) / totalChunks) * 100);
                if (totalChunks > 1 && (progress % 25 === 0 || progress === 100)) {
                  termLog(`[${file.name}] Uploading... ${progress}%`);
                }
              } catch (err) {
                termLog(`[${file.name}] Upload failed: ${err.message}`, true);
                success = false;
                break;
              }
            }
            if (success) {
              if (totalChunks <= 1) termLog(`[${file.name}] Uploaded successfully.`);
              else termLog(`[${file.name}] Stitching complete.`);
              totalUploaded++;
            }
          }
          if (totalUploaded > 0) {
            loadTree(targetPath);
          }
        };

        const loadTree = async (path = '') => {
          window.currentIdeTreePath = path;
          try {
            const res = await fetch(`?api=true&action=list&path=${encodeURIComponent(path)}`);
            const data = await res.json();
            if (data && data.success) renderTree(data, path);
          } catch (e) {
            treeEl.innerHTML = '<div class="text-danger p-2">Error loading files</div>';
          }
        };

        const renderTree = (data, basePath) => {
          let html = '';
          if (basePath) {
            const parent = basePath.split('/').slice(0, -1).join('/');
            html += `<div class="ide-tree-item ide-folder-toggle" data-path="${parent}"><i class="bi bi-arrow-90deg-up text-warning"></i> Back (..)</div>`;
          }
          data.folders.forEach(f => {
            html += `<div class="ide-tree-item ide-folder-toggle" data-path="${f.path}" data-name="${f.name}"><i class="bi bi-folder-fill text-warning"></i> ${f.name}</div>`;
          });
          data.files.forEach(f => {
            let icon = 'bi-file-earmark-code text-info';
            if (f.ext === 'php') icon = 'bi-filetype-php text-primary';
            if (f.ext === 'js' || f.ext === 'json') icon = 'bi-filetype-js text-warning';
            if (f.ext === 'css') icon = 'bi-filetype-css text-info';
            if (f.isImage) icon = 'bi-image text-success';
            if (['mp4', 'webm', 'mp3', 'wav', 'ogg'].includes(f.ext)) icon = 'bi-play-circle text-danger';
            html += `<div class="ide-tree-item ide-file-item" data-path="${f.path}" data-name="${f.name}" data-ext="${f.ext}" data-size="${f.size}" data-formatsize="${f.formatSize}"><i class="bi ${icon}"></i> ${f.name}</div>`;
          });
          treeEl.innerHTML = html;

          treeEl.querySelectorAll('.ide-folder-toggle').forEach(el => {
            el.addEventListener('click', (e) => {
              if (window.ideHasDragged) return;
              if (e.ctrlKey || e.shiftKey || e.metaKey) return;
              loadTree(el.dataset.path);
            });
            el.addEventListener('contextmenu', (e) => {
              e.preventDefault();
              window.showIdeContextMenu(el.dataset.path, el.dataset.name, true);
            });
          });

          treeEl.querySelectorAll('.ide-file-item').forEach(el => {
            el.addEventListener('click', (e) => {
              if (window.ideHasDragged) return;
              if (e.ctrlKey || e.shiftKey || e.metaKey) return;
              const path = el.dataset.path;
              const name = el.dataset.name;
              const ext = el.dataset.ext;
              const size = parseInt(el.dataset.size || '0');
              const formatSize = el.dataset.formatsize || '';
              const existingFile = openFiles.find(f => f.path === path);
              if (!existingFile) {
                openFiles.push({
                  path,
                  name,
                  ext,
                  size,
                  formatSize
                });
                localStorage.setItem('ide_open_files', JSON.stringify(openFiles));
              } else {
                existingFile.size = size;
                existingFile.formatSize = formatSize;
                localStorage.setItem('ide_open_files', JSON.stringify(openFiles));
              }
              window.ideOpenTab(path);
            });
            el.addEventListener('contextmenu', (e) => {
              e.preventDefault();
              window.showIdeContextMenu(el.dataset.path, el.dataset.name, false);
            });
          });

          treeEl.querySelectorAll('.ide-tree-item').forEach(i => i.classList.remove('active'));
          if (activeTabPath) {
            const activeEl = treeEl.querySelector(`[data-path="${activeTabPath}"]`);
            if (activeEl) activeEl.classList.add('active');
          }
          window.updateIdeSelectionUI();
        };

        const renderTabs = () => {
          tabsContainer.innerHTML = openFiles.map(f => `
            <div class="ide-tab ${f.path === activeTabPath ? 'active' : ''}" data-path="${f.path}">
              <span class="tab-title flex-grow-1" onclick="window.ideOpenTab('${f.path}')">${f.name}</span>
              <i class="bi bi-x ide-tab-close" onclick="window.ideCloseTab('${f.path}', event)"></i>
            </div>
          `).join('');

          if (openFiles.length === 0) {
            editorDiv.style.display = 'none';
            mediaViewer.classList.replace('d-flex', 'd-none');
            emptyState.classList.replace('d-none', 'd-flex');
            currentFileEl.textContent = 'No file selected';
            currentPath = '';
            activeTabPath = '';
            document.getElementById('ide-status-bar').style.display = 'none';
          }
        };

        tabsContainer.addEventListener('auxclick', (e) => {
          if (e.button === 1) {
            const tab = e.target.closest('.ide-tab');
            if (tab && tab.dataset.path) {
              e.preventDefault();
              window.ideCloseTab(tab.dataset.path, e);
            }
          }
        });
        tabsContainer.addEventListener('mousedown', (e) => {
          if (e.button === 1) e.preventDefault();
        });

        window.ideOpenTab = async (path) => {
          activeTabPath = path;
          currentPath = path;
          localStorage.setItem('ide_active_tab', path);
          renderTabs();

          if (treeEl) {
            treeEl.querySelectorAll('.ide-tree-item').forEach(i => i.classList.remove('active'));
            const safePath = path.replace(/"/g, '\\"');
            const activeEl = treeEl.querySelector(`[data-path="${safePath}"]`);
            if (activeEl) activeEl.classList.add('active');
          }
          document.getElementById('ide-status-bar').style.display = 'flex';

          const file = openFiles.find(f => f.path === path);
          if (!file) return;

          currentFileEl.textContent = path;
          emptyState.classList.replace('d-flex', 'd-none');

          if (mediaExts.includes(file.ext)) {
            editorDiv.style.display = 'none';
            mediaViewer.classList.replace('d-none', 'd-flex');
            const streamUrl = `?api=true&action=stream&file=${encodeURIComponent(path)}`;

            const docExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'odp', 'csv'];
            if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(file.ext)) {
              mediaContent.innerHTML = `<img src="${streamUrl}" id="ide-media-img" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
            } else if (['mp4', 'webm'].includes(file.ext)) {
              mediaContent.innerHTML = `<video src="${streamUrl}" id="ide-media-vid" controls preload="metadata" style="max-width: 100%; max-height: 100%; outline: none;"></video>`;
              document.getElementById('ide-media-vid').onloadedmetadata = function() {
                const resInfo = document.getElementById('media-res-info');
                if(resInfo) resInfo.textContent = ` | Res: ${this.videoWidth}x${this.videoHeight}`;
              };
            } else if (docExts.includes(file.ext)) {
              const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
              const absoluteStreamUrl = window.location.origin + window.location.pathname + streamUrl;
              let viewerSrc = streamUrl;
              if (file.ext !== 'pdf') {
                viewerSrc = isLocalhost ? streamUrl : `https://docs.google.com/viewer?url=${encodeURIComponent(absoluteStreamUrl)}&embedded=true`;
              }
              
              if (isLocalhost && file.ext !== 'pdf') {
                mediaContent.innerHTML = `
                  <div class="d-flex flex-column align-items-center justify-content-center text-center p-5 text-secondary">
                    <i class="bi bi-file-earmark-x fs-1 mb-3"></i>
                    <p>Google Docs Viewer cannot access localhost files.<br><a href="${streamUrl}" target="_blank" class="text-info">Download file</a></p>
                  </div>`;
              } else {
                mediaContent.innerHTML = `<iframe src="${viewerSrc}" style="width: 100%; height: 100%; border: none; background: #fff;"></iframe>`;
              }
            } else {
              mediaContent.innerHTML = `<i class="bi bi-music-note-beamed text-danger mb-3" style="font-size: 4rem;"></i><audio src="${streamUrl}" controls preload="metadata" style="width: 300px; outline: none;"></audio>`;
            }

            let displaySize = file.formatSize;
            if (!displaySize && file.size && !isNaN(parseInt(file.size)) && parseInt(file.size) > 0) {
              const s = parseInt(file.size);
              if (s < 1024) displaySize = s + ' B';
              else if (s < 1024 * 1024) displaySize = (s / 1024).toFixed(2) + ' KB';
              else displaySize = (s / (1024 * 1024)).toFixed(2) + ' MB';
            }
            document.getElementById('ide-media-info').innerHTML = `${path} <br> Size: ${displaySize || 'Unknown'}<span id="media-res-info"></span>`;
            updateIDEStatusBar();
            termLog(`Opened media file: ${path}`);
            fetchHistory(path, file.name);
          } else {
            if (file.size > 100 * 1024 * 1024) {
              editorDiv.style.display = 'none';
              mediaViewer.classList.replace('d-none', 'd-flex');
              mediaContent.innerHTML = `
                <i class="bi bi-file-earmark-x text-secondary mb-3" style="font-size: 4rem;"></i>
                <h5 class="fw-bold">File Exceeds 100MB</h5>
                <p class="text-secondary small">This file exceeds the 100MB limit.<br>You can manage or delete it via the explorer.</p>
              `;
              document.getElementById('ide-media-info').innerHTML = `${path} <br> Size: ${file.formatSize || 'Unknown'}`;
              termLog(`Blocked file exceeding 100MB: ${path}`, true);
              return;
            }

            let targetMode = "ace/mode/text";

            if (file.size > 5.0 * 1024 * 1024) {
              aceEditor.session.setUseWorker(false);
              targetMode = "ace/mode/text";
              try {
                aceEditor.setOptions({
                  enableBasicAutocompletion: false,
                  enableLiveAutocompletion: false,
                  enableSnippets: false,
                  wrap: false,
                  foldStyle: 'manual',
                  displayIndentGuides: false,
                  showFoldWidgets: false,
                  animatedScroll: false,
                  useWorker: false
                });
              } catch(e) {}
            } else if (file.size > 1.0 * 1024 * 1024) {
              aceEditor.session.setUseWorker(false);
              try {
                let modelist = ace.require("ace/ext/modelist");
                if (modelist) targetMode = modelist.getModeForPath(file.name).mode;
                aceEditor.setOptions({
                  enableBasicAutocompletion: false,
                  enableLiveAutocompletion: false,
                  wrap: false,
                  foldStyle: 'manual',
                  useWorker: false
                });
              } catch(e) {}
            } else {
              aceEditor.session.setUseWorker(true);
              try {
                let modelist = ace.require("ace/ext/modelist");
                if (modelist) targetMode = modelist.getModeForPath(file.name).mode;
              } catch(e) {}
              try {
                aceEditor.setOptions({
                  enableBasicAutocompletion: true,
                  enableLiveAutocompletion: true,
                  enableSnippets: true,
                  wrap: localStorage.getItem('ide_wrap') === 'true'
                });
              } catch(e) {
                aceEditor.setOption('wrap', localStorage.getItem('ide_wrap') === 'true');
              }
              try {
                const langTools = ace.require("ace/ext/language_tools");
                if (langTools) {
                  const advancedPhpCompleter = {
                    getCompletions: function(editor, session, pos, prefix, callback) {
                      let completions = [
                        {caption: "Route::get", value: "Route::get('/${1:path}', function () {\n    return view('${2:view}');\n});", meta: "Laravel"},
                        {caption: "Route::post", value: "Route::post('/${1:path}', [${2:Controller}::class, '${3:method}']);", meta: "Laravel"},
                        {caption: "$this->render", value: "$this->render('${1:template.html.twig}', [\n    '${2:var}' => $${3:val},\n]);", meta: "Symfony"},
                        {caption: "dd()", value: "dd($${1:var});", meta: "Debug"},
                        {caption: "dump()", value: "dump($${1:var});", meta: "Debug"},
                        {caption: "Log::info", value: "Log::info('${1:message}', ['${2:context}' => $${3:var}]);", meta: "Laravel"},
                        {caption: "public function", value: "public function ${1:name}() {\n    ${2}\n}", meta: "Method"},
                        {caption: "private function", value: "private function ${1:name}() {\n    ${2}\n}", meta: "Method"},
                        {caption: "protected function", value: "protected function ${1:name}() {\n    ${2}\n}", meta: "Method"},
                        {caption: "public static function", value: "public static function ${1:name}() {\n    ${2}\n}", meta: "Method"},
                        {caption: "__construct", value: "public function __construct(${1}) {\n    ${2}\n}", meta: "Magic"},
                        {caption: "class", value: "class ${1:Name} {\n    ${2}\n}", meta: "OOP"},
                        {caption: "interface", value: "interface ${1:Name} {\n    ${2}\n}", meta: "OOP"},
                        {caption: "trait", value: "trait ${1:Name} {\n    ${2}\n}", meta: "OOP"},
                        {caption: "try", value: "try {\n    ${1}\n} catch (\\Exception \\$e) {\n    ${2}\n}", meta: "PHP"}
                      ];

                      const line = session.getLine(pos.row);
                      const linePrefix = line.slice(0, pos.column);
                      
                      if (linePrefix.match(/(?:\$this->|self::)[a-zA-Z0-9_]*$/)) {
                        const fullText = session.getValue();
                        
                        const methodRegex = /function\s+([a-zA-Z0-9_]+)\s*\(/g;
                        let m;
                        while ((m = methodRegex.exec(fullText)) !== null) {
                          completions.push({
                            caption: m[1] + '()',
                            value: m[1] + "(${1})",
                            meta: "Local Method"
                          });
                        }
                        
                        const propRegex = /(?:public|protected|private|var)\s+\$([a-zA-Z0-9_]+)/g;
                        let p;
                        while ((p = propRegex.exec(fullText)) !== null) {
                          completions.push({
                            caption: p[1],
                            value: p[1],
                            meta: "Local Property"
                          });
                        }
                      }

                      callback(null, completions);
                    }
                  };
                  langTools.setCompleters([langTools.snippetCompleter, langTools.textCompleter, langTools.keyWordCompleter, advancedPhpCompleter]);
                }
              } catch(e) {}
            }

            mediaViewer.classList.replace('d-flex', 'd-none');
            editorDiv.style.display = 'block';
            termLog(`Fetching text buffer: ${path}`);

            try {
              const res = await fetch(`?api=true&action=read&file=${encodeURIComponent(path)}&t=${Date.now()}`);
              if (!res.ok) throw new Error(`HTTP ${res.status}`);
              const data = await res.json();
              if (data && data.success) {
                if (path !== activeTabPath) {
                  termLog(`Discarded stale buffer for ${path} (Switched tabs).`);
                  return;
                }
                const safeContent = data.content || '';
                
                window.isIdeLoadingFile = true;
                if (file.size > 5.0 * 1024 * 1024) {
                  aceEditor.session.setValue(safeContent);
                } else {
                  aceEditor.setValue(safeContent, -1);
                }
                
                try {
                  const um = aceEditor.session.getUndoManager();
                  if (um) um.markClean();
                } catch(e) {}
                window.isIdeLoadingFile = false;

                aceEditor.session.setMode(targetMode);
                setTimeout(() => {
                  aceEditor.resize(true);
                  aceEditor.clearSelection();
                }, 100);
                termLog(`Loaded ${safeContent.length} bytes.`);
                fetchHistory(path, file.name);
                if (bottomPanel.classList.contains('active')) window.updateIdeOutputPreview();
              } else {
                termLog(`Failed to read file: ${data.error}`, true);
              }
            } catch (err) {
              termLog(`Network Error reading file: ${err.message}`, true);
            }
          }
        };

        window.ideCloseTab = async (path, e) => {
          if (e && e.stopPropagation) e.stopPropagation();
          const targetTabTitle = document.querySelector(`.ide-tab[data-path="${path.replace(/"/g, '\\"')}"] .tab-title`);
          const isDirty = targetTabTitle && targetTabTitle.innerText.endsWith(' *');
          const isAutosaveOn = localStorage.getItem('ide_autosave') !== 'false';
          
          if (isDirty) {
            if (isAutosaveOn && path === currentPath) {
              await window.saveCurrentFile(true);
            } else if (!confirm(`You have unsaved changes in this file. Do you really want to close it and lose your changes?`)) {
              return;
            }
          }
          const idx = openFiles.findIndex(f => f.path === path);
          openFiles = openFiles.filter(f => f.path !== path);
          localStorage.setItem('ide_open_files', JSON.stringify(openFiles));

          if (activeTabPath === path) {
            if (openFiles.length > 0) {
              const nextIdx = Math.min(idx, openFiles.length - 1);
              window.ideOpenTab(openFiles[nextIdx].path);
            } else {
              activeTabPath = '';
              currentPath = '';
              localStorage.removeItem('ide_active_tab');
              renderTabs();
            }
          } else {
            renderTabs();
          }
        };

        const fetchHistory = async (path, name) => {
          const histPane = document.getElementById('ide-history-tree');
          if(!histPane) return;
          const data = await driveFetch('get_versions', { action: 'get_versions', file: path }, path);
          if (data && data.success && data.versions && data.versions.length > 0) {
            histPane.innerHTML = `<div class="mb-3 text-white fw-bold d-flex align-items-center justify-content-between" style="font-size: 0.82rem;"><span class="text-truncate me-2"><i class="bi bi-clock-history text-danger me-1"></i> ${name}</span><span class="badge bg-dark border border-secondary text-secondary">${data.versions.length}</span></div>` + 
              data.versions.map(v => `
                <div class="p-2 rounded-3 mb-2 shadow-sm" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-white fw-medium small d-flex align-items-center gap-1" style="font-size: 0.75rem;">
                      <i class="bi bi-clock text-secondary"></i> ${new Date(v.mtime * 1000).toLocaleString([], {month:'numeric', day:'numeric', year:'2-digit', hour:'2-digit', minute:'2-digit'})}
                    </span>
                    <span class="badge bg-black text-secondary border border-secondary" style="font-size: 0.68rem;">${v.size}</span>
                  </div>
                  <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-info flex-grow-1 py-1 px-2 fw-semibold d-flex align-items-center justify-content-center gap-1" style="font-size: 0.75rem;" onclick="window.ideDiffVersion('${path}', '${v.name}')"><i class="bi bi-file-diff"></i> Diff</button>
                    <button class="btn btn-sm btn-outline-warning flex-grow-1 py-1 px-2 fw-semibold d-flex align-items-center justify-content-center gap-1" style="font-size: 0.75rem;" onclick="window.ideRestoreVersion('${path}', '${v.name}')"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                  </div>
                </div>
              `).join('');
          } else {
            histPane.innerHTML = `<div class="text-secondary small p-2"><i class="bi bi-info-circle me-1"></i> No version history found for ${name}.</div>`;
          }
        };

        window.ideDiffVersion = async (path, versionName) => {
          const diffModalEl = document.getElementById('ide-diff-modal');
          const diffBody = document.getElementById('ide-diff-body');
          const diffTitle = document.getElementById('ide-diff-title');
          if (!diffModalEl || !diffBody) return;

          const fileName = path.split('/').pop();
          if (diffTitle) diffTitle.textContent = `Diff: ${fileName} (Old vs Current)`;
          diffBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info" role="status"></div><div class="text-secondary mt-2 small font-monospace">Computing diff changes...</div></div>';
          
          const modal = bootstrap.Modal.getOrCreateInstance(diffModalEl);
          modal.show();

          try {
            const currentRes = await fetch(`?api=true&action=read&file=${encodeURIComponent(path)}&t=${Date.now()}`);
            const currentData = await currentRes.json();
            
            const oldRes = await fetch(`?api=true&action=read&file=${encodeURIComponent('.file_version/' + fileName + '/' + versionName)}&t=${Date.now()}`);
            const oldData = await oldRes.json();
            
            if (currentData && currentData.success && oldData && oldData.success) {
              if (typeof diff_match_patch !== 'undefined') {
                const dmp = new diff_match_patch();
                const diffs = dmp.diff_main(oldData.content || '', currentData.content || '');
                dmp.diff_cleanupSemantic(diffs);
                
                const escapeHTML = str => (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                
                let diffHtml = '';
                diffs.forEach(diff => {
                  const op = diff[0];
                  const text = escapeHTML(diff[1]);
                  if (op === 1) {
                    diffHtml += `<ins style="background: rgba(34, 197, 94, 0.25); color: #86efac; text-decoration: none; padding: 1px 3px; border-radius: 2px;">${text}</ins>`;
                  } else if (op === -1) {
                    diffHtml += `<del style="background: rgba(239, 68, 68, 0.25); color: #fca5a5; text-decoration: none; padding: 1px 3px; border-radius: 2px;">${text}</del>`;
                  } else {
                    diffHtml += text;
                  }
                });
                
                diffBody.innerHTML = diffHtml || '<span class="text-secondary">Files are identical. No differences found.</span>';
              } else {
                diffBody.innerHTML = '<div class="alert alert-danger m-0">Diff match patch library is not loaded.</div>';
              }
            } else {
              diffBody.innerHTML = `<div class="alert alert-danger m-0">Failed to read version contents.</div>`;
            }
          } catch (e) {
            diffBody.innerHTML = `<div class="alert alert-danger m-0">Error calculating diff: ${e.message}</div>`;
          }
        };

        window.ideRestoreVersion = async (path, versionName) => {
          if (!confirm(`Restore version ${versionName}? Current state will be backed up.`)) return;
          termLog(`Restoring version ${versionName} for ${path}...`);
          const data = await driveFetch('restore_version', { action: 'restore_version', file: path, version_name: versionName }, path);
          if (data && data.success) {
            termLog(`Restore successful. Reloading buffer.`);
            window.ideOpenTab(path);
          } else {
            termLog(`Restore failed: ${data ? data.error : 'Unknown error'}`, true);
          }
        };

        aceEditor.on("change", () => {
          if (window.isIdeLoadingFile) return;
          const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath.replace(/"/g, '\\"')}"] .tab-title`);
          if (activeTab) {
            const um = aceEditor.session.getUndoManager();
            const isClean = um ? um.isClean() : false;
            
            if (!isClean && !activeTab.innerText.endsWith(' *')) {
              activeTab.innerText += ' *';
            } else if (isClean && activeTab.innerText.endsWith(' *')) {
              activeTab.innerText = activeTab.innerText.replace(/\s*\*\s*$/, '');
            }
          }
        });

        window.addEventListener('beforeunload', (e) => {
          const dirtyTabs = document.querySelectorAll('.ide-tab .tab-title');
          let hasDirty = false;
          dirtyTabs.forEach(tab => {
            if (tab.innerText.endsWith(' *')) hasDirty = true;
          });
          if (hasDirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
          }
        });

        window.saveCurrentFile = async (silent = false) => {
          if (!currentPath) return;
          const file = openFiles.find(f => f.path === currentPath);
          if (file && mediaExts.includes(file.ext)) return;

          const btn = document.getElementById('ide-save-btn');
          const orig = btn.innerHTML;
          if (!silent) btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

          const content = aceEditor.getValue();
          const data = await driveFetch('write', { action: 'write', file: currentPath, content: content }, currentPath);

          if (data && data.success) {
            try {
              const um = aceEditor.session.getUndoManager();
              if (um) um.markClean();
            } catch(e) {}

            const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath.replace(/"/g, '\\"')}"] .tab-title`);
            if (activeTab) {
              activeTab.innerText = activeTab.innerText.replace(/\s*\*\s*$/, '');
            }
            
            if (!silent) {
              btn.innerHTML = '<i class="bi bi-check"></i> Saved';
              btn.classList.add('text-success');
              termLog(`Saved ${currentPath} successfully.`);
              fetchHistory(currentPath, file.name);
              
              if (bottomPanel.classList.contains('active')) window.updateIdeOutputPreview();
              setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('text-success'); }, 2000);
            }
          } else {
            if (!silent) {
              btn.innerHTML = orig;
              termLog(`Save failed: ${data ? data.error : 'Unknown error'}`, true);
            }
          }
        };

        setInterval(() => {
          if (localStorage.getItem('ide_autosave') !== 'false') {
            const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath.replace(/"/g, '\\"')}"] .tab-title`);
            if (currentPath && activeTab && activeTab.innerText.endsWith(' *')) window.saveCurrentFile(true);
          }
        }, 10000);

        document.getElementById('ide-save-btn').onclick = () => window.saveCurrentFile(false);

        window.showIdeContextMenu = (path, name, isFolder) => {
          const modal = document.getElementById('ide-ctx-modal');
          const multiCount = window.ideSelectedItems.size;

          if (multiCount > 1) {
            document.getElementById('ide-ctx-title').innerText = `${multiCount} items selected`;
            document.getElementById('ide-ctx-path').value = Array.from(window.ideSelectedItems).join('|');
            document.getElementById('ide-ctx-is-folder').value = '0'; 
            
            document.getElementById('ide-btn-new-file').style.display = 'none';
            document.getElementById('ide-btn-new-folder').style.display = 'none';
            document.getElementById('ide-btn-rename').style.display = 'none';
            const copyBtn = document.getElementById('ide-btn-copy');
            if (copyBtn) copyBtn.style.display = 'flex';
            const cutBtn = document.getElementById('ide-btn-cut');
            if (cutBtn) cutBtn.style.display = 'flex';
            const pasteBtn = document.getElementById('ide-btn-paste');
            if (pasteBtn) pasteBtn.style.display = 'none';
            const downloadBtn = document.getElementById('ide-btn-download');
            if (downloadBtn) downloadBtn.style.display = 'flex';
            const propBtn = document.getElementById('ide-btn-properties');
            if (propBtn) propBtn.style.display = 'flex';
            document.getElementById('ide-btn-delete').style.display = 'flex';
            const zipBtn = document.getElementById('ide-btn-zip');
            if (zipBtn) zipBtn.style.display = 'flex';
            const unzipBtn = document.getElementById('ide-btn-unzip');
            if (unzipBtn) unzipBtn.style.display = 'none';
          } else {
            document.getElementById('ide-ctx-title').innerText = name || '/ (Root)';
            document.getElementById('ide-ctx-path').value = path || '';
            document.getElementById('ide-ctx-is-folder').value = isFolder ? '1' : '0';

            const isRoot = path === '';
            document.getElementById('ide-btn-new-file').style.display = isFolder ? 'flex' : 'none';
            document.getElementById('ide-btn-new-folder').style.display = isFolder ? 'flex' : 'none';
            document.getElementById('ide-btn-rename').style.display = isRoot ? 'none' : 'flex';
            const copyBtn = document.getElementById('ide-btn-copy');
            if (copyBtn) copyBtn.style.display = isRoot ? 'none' : 'flex';
            const cutBtn = document.getElementById('ide-btn-cut');
            if (cutBtn) cutBtn.style.display = isRoot ? 'none' : 'flex';
            const pasteBtn = document.getElementById('ide-btn-paste');
            if (pasteBtn) pasteBtn.style.display = (isFolder && window.ideClipboard && window.ideClipboard.items && window.ideClipboard.items.length > 0) ? 'flex' : 'none';
            const downloadBtn = document.getElementById('ide-btn-download');
            if (downloadBtn) downloadBtn.style.display = isRoot ? 'none' : 'flex';
            const propBtn = document.getElementById('ide-btn-properties');
            if (propBtn) propBtn.style.display = isRoot ? 'none' : 'flex';
            document.getElementById('ide-btn-delete').style.display = isRoot ? 'none' : 'flex';
            const zipBtn = document.getElementById('ide-btn-zip');
            if (zipBtn) zipBtn.style.display = isRoot ? 'none' : 'flex';
            const unzipBtn = document.getElementById('ide-btn-unzip');
            if (unzipBtn) unzipBtn.style.display = (!isFolder && path.endsWith('.zip')) ? 'flex' : 'none';
          }
          modal.style.display = 'flex';
        };

        const handleUploadClick = (targetPath) => {
          const fileInput = document.createElement('input');
          fileInput.type = 'file';
          fileInput.multiple = true;
          fileInput.onchange = async (e) => {
            if (e.target.files.length > 0) {
              const filesArr = Array.from(e.target.files);
              const pathsArr = filesArr.map(f => f.name);
              await window.ideChunkedUpload(filesArr, pathsArr, targetPath);
            }
          };
          fileInput.click();
        };

        const ideSearchInput = document.getElementById('ide-sidebar-search-input');
        const ideSearchContainer = document.getElementById('ide-sidebar-search-container');
        document.getElementById('ide-tree-search').onclick = () => {
          ideSearchContainer.classList.toggle('d-none');
          if (!ideSearchContainer.classList.contains('d-none')) {
            ideSearchInput.focus();
          } else {
            ideSearchInput.value = '';
            ideSearchInput.dispatchEvent(new Event('input'));
          }
        };

        let ideSearchTimeout = null;
        ideSearchInput.addEventListener('input', (e) => {
          const q = e.target.value.trim();
          clearTimeout(ideSearchTimeout);
          if (q === '') {
            loadTree(window.currentIdeTreePath || '');
            return;
          }
          ideSearchTimeout = setTimeout(async () => {
            const treeEl = document.getElementById('ide-file-tree');
            if (!treeEl) return;
            treeEl.innerHTML = '<div class="text-center mt-4 text-secondary"><i class="spinner-border spinner-border-sm"></i> Searching workspace...</div>';
            try {
              const res = await fetch(`?api=true&action=search_drive&q=${encodeURIComponent(q)}`);
              if (!res.ok) throw new Error('Network error');
              const data = await res.json();
              if (data && data.success) {
                if (data.folders.length === 0 && data.files.length === 0) {
                  treeEl.innerHTML = '<div class="text-secondary p-2 text-center mt-3">No files found.</div>';
                  return;
                }
                data.folders.forEach(f => f.name = f.path);
                data.files.forEach(f => f.name = f.path);
                renderTree(data, ''); 
              } else {
                treeEl.innerHTML = `<div class="text-danger p-2 text-center">Search failed: ${data.error || 'Unknown error'}</div>`;
              }
            } catch (err) {
              treeEl.innerHTML = '<div class="text-danger p-2 text-center">Search connection error.</div>';
            }
          }, 400);
        });

        const btnNewFile = document.getElementById('ide-tree-new-file');
        if (btnNewFile) btnNewFile.onclick = async () => {
          const name = prompt('Enter new file name:');
          if (name) {
            const cp = window.currentIdeTreePath || '';
            const targetName = cp ? cp + '/' + name : name;
            const res = await driveFetch('add_file', { action: 'add_file', name: targetName }, cp);
            if (res.success) loadTree(cp); else alert(res.error);
          }
        };
        const btnNewFolder = document.getElementById('ide-tree-new-folder');
        if (btnNewFolder) btnNewFolder.onclick = async () => {
          const name = prompt('Enter new folder name:');
          if (name) {
            const cp = window.currentIdeTreePath || '';
            const targetName = cp ? cp + '/' + name : name;
            const res = await driveFetch('add_folder', { action: 'add_folder', name: targetName }, cp);
            if (res.success) loadTree(cp); else alert(res.error);
          }
        };
        const btnUpload = document.getElementById('ide-tree-upload');
        if (btnUpload) btnUpload.onclick = () => handleUploadClick(window.currentIdeTreePath || '');
        const btnRefresh = document.getElementById('ide-refresh-tree');
        if (btnRefresh) btnRefresh.onclick = () => loadTree(window.currentIdeTreePath || '');

        window.updateIdeClipboardUI = () => {
          const container = document.getElementById('ide-sidebar-clipboard-container');
          const status = document.getElementById('ide-clipboard-status');
          if (window.ideClipboard && window.ideClipboard.items && window.ideClipboard.items.length > 0) {
            const actionText = window.ideClipboard.action === 'move_items' ? 'Cut' : 'Copied';
            status.innerText = `${window.ideClipboard.items.length} item(s) ${actionText}`;
            container.classList.remove('d-none');
          } else {
            container.classList.add('d-none');
          }
        };

        const doIdePaste = async (targetPath) => {
          if (window.ideClipboard && window.ideClipboard.items.length > 0) {
            const action = window.ideClipboard.action;
            termLog(`Pasting ${window.ideClipboard.items.length} item(s) into ${targetPath || 'root'}...`);
            
            let res = await driveFetch(action, { action: action, items: window.ideClipboard.items, target: targetPath }, targetPath);
            
            if (res.success) {
              window.ideClipboard = null; 
              window.updateIdeClipboardUI();
              loadTree(targetPath);
              termLog('Paste successful.');
            } else if (res.error) {
              alert(res.error);
            }
          }
        };

        const clipboardPasteBtn = document.getElementById('ide-btn-clipboard-paste');
        if (clipboardPasteBtn) {
          clipboardPasteBtn.onclick = () => { doIdePaste(window.currentIdeTreePath || ''); };
        }
        
        const clipboardCancelBtn = document.getElementById('ide-btn-cancel-clipboard');
        if (clipboardCancelBtn) {
          clipboardCancelBtn.onclick = () => {
             window.ideClipboard = null;
             window.updateIdeClipboardUI();
          };
        }

        const clipboardNewFolderBtn = document.getElementById('ide-btn-clipboard-new-folder');
        if (clipboardNewFolderBtn) {
          clipboardNewFolderBtn.onclick = async () => {
            const parentPath = window.currentIdeTreePath || '';
            const name = prompt('Enter new folder name:');
            if (name) {
              const targetPath = (parentPath ? parentPath + '/' : '');
              const res = await driveFetch('add_folder', { action: 'add_folder', name: targetPath + name }, parentPath);
              if (res.success) loadTree(parentPath); else alert(res.error);
            }
          };
        }

        document.getElementById('ide-btn-copy').onclick = () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          const paths = pathStr.split('|').filter(p => p);
          window.ideClipboard = { action: 'copy_items', items: paths };
          document.getElementById('ide-ctx-modal').style.display = 'none';
          termLog(`Copied ${paths.length} item(s) to clipboard.`);
          window.updateIdeClipboardUI();
          window.ideSelectedItems.clear();
          window.updateIdeSelectionUI();
        };

        document.getElementById('ide-btn-cut').onclick = () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          const paths = pathStr.split('|').filter(p => p);
          window.ideClipboard = { action: 'move_items', items: paths };
          document.getElementById('ide-ctx-modal').style.display = 'none';
          termLog(`Cut ${paths.length} item(s) to clipboard.`);
          window.updateIdeClipboardUI();
          window.ideSelectedItems.clear();
          window.updateIdeSelectionUI();
        };

        document.getElementById('ide-btn-paste').onclick = () => {
          const targetPath = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          doIdePaste(targetPath);
        };

        document.getElementById('ide-btn-download').onclick = () => {
          const path = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          if (path) {
            window.location.href = `?api=true&action=download&file=${encodeURIComponent(path)}`;
          }
        };

        document.getElementById('ide-btn-new-file').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          const isFolder = document.getElementById('ide-ctx-is-folder').value === '1';
          const parentPath = isFolder ? path : (path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '');
          
          const name = prompt('Enter new file name:');
          if (name) {
            const targetPath = (parentPath ? parentPath + '/' : '');
            const res = await driveFetch('add_file', { action: 'add_file', name: targetPath + name }, parentPath);
            if (res.success) loadTree(parentPath); else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-new-folder').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          const isFolder = document.getElementById('ide-ctx-is-folder').value === '1';
          const parentPath = isFolder ? path : (path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '');
          
          const name = prompt('Enter new folder name:');
          if (name) {
            const targetPath = (parentPath ? parentPath + '/' : '');
            const res = await driveFetch('add_folder', { action: 'add_folder', name: targetPath + name }, parentPath);
            if (res.success) loadTree(parentPath); else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-properties').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          
          const propModalEl = document.getElementById('ide-properties-modal');
          const propBody = document.getElementById('ide-properties-body');
          propBody.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-danger"></div></div>';
          
          const modal = bootstrap.Modal.getOrCreateInstance(propModalEl);
          modal.show();

          try {
            const res = await fetch(`?api=true&action=properties&file=${encodeURIComponent(path)}`).then(r => r.json());
            if (res && res.success && res.data) {
              const p = res.data;
              propBody.innerHTML = `
                <div class="d-flex flex-column gap-2" style="font-size: 0.9rem;">
                  <div><strong class="text-danger">Name:</strong> ${p.name}</div>
                  <div><strong class="text-danger">Type:</strong> ${p.type}</div>
                  <div><strong class="text-danger">Size:</strong> ${p.size}</div>
                  ${p.contents ? `<div><strong class="text-danger">Contents:</strong> ${p.contents}</div>` : ''}
                  <div><strong class="text-danger">Modified:</strong> ${p.modified}</div>
                  <div><strong class="text-danger">Created:</strong> ${p.created}</div>
                  <div><strong class="text-danger">Permissions:</strong> ${p.permissions}</div>
                </div>
              `;
            } else {
              propBody.innerHTML = `<div class="alert alert-danger py-2 mb-0">${res.error || 'Failed to retrieve properties.'}</div>`;
            }
          } catch (e) {
            propBody.innerHTML = `<div class="alert alert-danger py-2 mb-0">Network error fetching properties.</div>`;
          }
        };

        let currentRenameTarget = { path: '', parentPath: '', oldName: '' };

        document.getElementById('ide-btn-rename').onclick = () => {
          const path = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          if (!path) return;

          const oldName = path.includes('/') ? path.substring(path.lastIndexOf('/') + 1) : path;
          const parentPath = path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '';
          currentRenameTarget = { path, parentPath, oldName };

          const renameInput = document.getElementById('ide-rename-input');
          renameInput.value = oldName;

          const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ide-rename-modal'));
          modal.show();
          setTimeout(() => {
            renameInput.focus();
            renameInput.select();
          }, 150);
        };

        const handleRenameSubmit = async () => {
          const name = document.getElementById('ide-rename-input').value.trim();
          const { path, parentPath, oldName } = currentRenameTarget;

          if (name && name !== oldName && path) {
            const submitBtn = document.getElementById('ide-rename-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const res = await driveFetch('rename', { action: 'rename', old: path, new: name }, parentPath);
            submitBtn.disabled = false;
            submitBtn.innerText = 'Rename';

            if (res.success) {
              const openFile = openFiles.find(f => f.path === path);
              if (openFile) {
                const newPath = parentPath ? parentPath + '/' + name : name;
                openFile.path = newPath;
                openFile.name = name;
                openFile.ext = name.split('.').pop().toLowerCase();
                if (currentPath === path) currentPath = newPath;
                if (activeTabPath === path) activeTabPath = newPath;
                localStorage.setItem('ide_open_files', JSON.stringify(openFiles));
                localStorage.setItem('ide_active_tab', activeTabPath);
                renderTabs();
              }
              loadTree(parentPath);
              bootstrap.Modal.getInstance(document.getElementById('ide-rename-modal')).hide();
            } else {
              alert(res.error || 'Rename failed.');
            }
          } else if (name === oldName) {
            bootstrap.Modal.getInstance(document.getElementById('ide-rename-modal')).hide();
          }
        };

        document.getElementById('ide-rename-submit').onclick = handleRenameSubmit;

        document.getElementById('ide-rename-input').addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            handleRenameSubmit();
          }
        });

        document.getElementById('ide-btn-zip').onclick = async () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          const paths = pathStr.split('|').filter(p => p);
          if (paths.length > 0) {
            const parentPath = paths[0].includes('/') ? paths[0].substring(0, paths[0].lastIndexOf('/')) : '';
            const btn = document.getElementById('ide-btn-zip');
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Zipping...';
            btn.style.pointerEvents = 'none';
            termLog(`Zipping ${paths.length} item(s)...`);
            
            const res = await driveFetch('zip_items', { action: 'zip_items', items: paths }, parentPath);
            
            btn.innerHTML = origHtml;
            btn.style.pointerEvents = 'auto';
            if (res.success) { loadTree(parentPath); termLog('Zip successful.'); }
            else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
          window.ideSelectedItems.clear();
          window.updateIdeSelectionUI();
        };

        document.getElementById('ide-btn-unzip').onclick = async () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          if (pathStr && pathStr.endsWith('.zip')) {
            const parentPath = pathStr.includes('/') ? pathStr.substring(0, pathStr.lastIndexOf('/')) : '';
            const btn = document.getElementById('ide-btn-unzip');
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Extracting...';
            btn.style.pointerEvents = 'none';
            termLog(`Extracting ${pathStr}...`);
            
            const res = await driveFetch('unzip', { action: 'unzip', item: pathStr }, parentPath);
            
            btn.innerHTML = origHtml;
            btn.style.pointerEvents = 'auto';
            if (res.success) { loadTree(parentPath); termLog('Extraction successful.'); }
            else alert(res.error);
          } else {
            alert('Please select a single .zip file to extract.');
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
          window.ideSelectedItems.clear();
          window.updateIdeSelectionUI();
        };

        document.getElementById('ide-btn-upload').onclick = () => {
          const path = document.getElementById('ide-ctx-path').value;
          const isFolder = document.getElementById('ide-ctx-is-folder').value === '1';
          const parentPath = isFolder ? path : (path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '');
          document.getElementById('ide-ctx-modal').style.display = 'none';
          handleUploadClick(parentPath);
        };

        document.getElementById('ide-btn-refresh').onclick = () => {
          document.getElementById('ide-ctx-modal').style.display = 'none';
          loadTree(window.currentIdeTreePath || '');
        };

        document.getElementById('ide-btn-delete').onclick = async () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          const paths = pathStr.split('|').filter(p => p);
          if (paths.length > 0) {
            const msg = paths.length === 1 ? `Are you sure you want to delete "${paths[0]}"?` : `Are you sure you want to delete ${paths.length} item(s)?`;
            if (confirm(msg)) {
              const parentPath = paths[0].includes('/') ? paths[0].substring(0, paths[0].lastIndexOf('/')) : '';
              const res = await driveFetch('delete', { action: 'delete', items: paths }, parentPath);
              if (res.success) {
                paths.forEach(p => {
                  const openFileIdx = openFiles.findIndex(f => f.path === p);
                  if (openFileIdx !== -1) {
                    window.ideCloseTab(p, {stopPropagation:()=>{}});
                  }
                });
                window.ideSelectedItems.clear();
                window.updateIdeSelectionUI();
                loadTree(parentPath); 
              } else alert(res.error);
            }
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        const treeContainer = document.getElementById('ide-file-tree');

        treeContainer.addEventListener('contextmenu', (e) => {
          if (e.target.id === 'ide-file-tree') {
            e.preventDefault();
            window.showIdeContextMenu('', 'Workspace', true);
          }
        });

        window.ideHasDragged = false;

        treeContainer.addEventListener('mousedown', (e) => {
          if (e.button !== 0) return;
          const item = e.target.closest('.ide-tree-item');
          
          isIdeSelecting = true;
          window.ideHasDragged = false;
          ideSelectStartX = e.clientX;
          ideSelectStartY = e.clientY;
          
          if (e.ctrlKey || e.metaKey) {
            if (item && item.dataset.path) {
              if (window.ideSelectedItems.has(item.dataset.path)) {
                window.ideSelectedItems.delete(item.dataset.path);
              } else {
                window.ideSelectedItems.add(item.dataset.path);
              }
              window.updateIdeSelectionUI();
            }
          } else if (e.shiftKey) {
            if (item && item.dataset.path) {
              window.ideSelectedItems.add(item.dataset.path);
              window.updateIdeSelectionUI();
            }
          } else {
            if (!item || !window.ideSelectedItems.has(item.dataset.path)) {
              window.ideSelectedItems.clear();
              if (item && item.dataset.path) window.ideSelectedItems.add(item.dataset.path);
              window.updateIdeSelectionUI();
            }
          }
          
          baseIdeSelected = new Set(window.ideSelectedItems);
          
          ideSelectionBox = document.createElement('div');
          ideSelectionBox.style.position = 'fixed';
          ideSelectionBox.style.border = '1px solid #ff0000';
          ideSelectionBox.style.backgroundColor = 'rgba(255, 0, 0, 0.15)';
          ideSelectionBox.style.zIndex = '9999';
          ideSelectionBox.style.pointerEvents = 'none';
          ideSelectionBox.style.display = 'none'; 
          ideSelectionBox.className = 'ide-selection-box';
          document.body.appendChild(ideSelectionBox);
        });

        document.addEventListener('mousemove', (e) => {
          if (!isIdeSelecting || !ideSelectionBox) return;
          
          const currentX = e.clientX;
          const currentY = e.clientY;
          const width = Math.abs(currentX - ideSelectStartX);
          const height = Math.abs(currentY - ideSelectStartY);
          
          if (width > 5 || height > 5) {
            ideSelectionBox.style.display = 'block';
            window.ideHasDragged = true;
          } else {
            return;
          }
          
          const left = Math.min(ideSelectStartX, currentX);
          const top = Math.min(ideSelectStartY, currentY);
          
          ideSelectionBox.style.left = left + 'px';
          ideSelectionBox.style.top = top + 'px';
          ideSelectionBox.style.width = width + 'px';
          ideSelectionBox.style.height = height + 'px';
          
          window.ideSelectedItems = new Set(baseIdeSelected);
          document.querySelectorAll('.ide-tree-item').forEach(el => {
            const rect = el.getBoundingClientRect();
            const intersect = !(rect.right < left || 
                                rect.left > left + width || 
                                rect.bottom < top || 
                                rect.top > top + height);
            
            if (intersect && el.dataset.path) {
              window.ideSelectedItems.add(el.dataset.path);
            }
          });
          
          window.updateIdeSelectionUI();
        });

        const stopIdeSelection = (e) => {
          if (isIdeSelecting) {
            isIdeSelecting = false;
            if (ideSelectionBox) {
              ideSelectionBox.remove();
              ideSelectionBox = null;
            }
            document.querySelectorAll('.ide-selection-box').forEach(box => box.remove());

            if (!window.ideHasDragged && e && e.target) {
              const item = e.target.closest('.ide-tree-item');
              if (item && item.dataset.path && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                window.ideSelectedItems.clear();
                window.ideSelectedItems.add(item.dataset.path);
                window.updateIdeSelectionUI();
              }
            }
          }
        };

        document.addEventListener('mouseup', stopIdeSelection);
        document.addEventListener('mouseleave', stopIdeSelection);

        window.switchIdeSidebar = (view) => {
          const sidebar = document.getElementById('ide-main-sidebar');
          const actionEl = document.getElementById('act-' + view);

          if (actionEl.classList.contains('active')) {
            sidebar.classList.toggle('d-none');
            actionEl.classList.toggle('active');
            setTimeout(() => aceEditor.resize(true), 50);
            return;
          }

          sidebar.classList.remove('d-none');
          document.querySelectorAll('.ide-activity-action').forEach(el => el.classList.remove('active'));
          actionEl.classList.add('active');

          document.getElementById('ide-file-tree').classList.add('d-none');
          document.getElementById('ide-history-tree').classList.add('d-none');

          if (view === 'explorer') {
            document.getElementById('ide-sidebar-title').innerHTML = '<span>EXPLORER</span><div class="d-flex gap-2"><i class="bi bi-search" style="cursor:pointer;" id="ide-tree-search" title="Search Files"></i><i class="bi bi-file-earmark-plus" style="cursor:pointer;" id="ide-tree-new-file" title="New File"></i><i class="bi bi-folder-plus" style="cursor:pointer;" id="ide-tree-new-folder" title="New Folder"></i><i class="bi bi-upload" style="cursor:pointer;" id="ide-tree-upload" title="Upload"></i><i class="bi bi-arrow-clockwise" style="cursor:pointer;" id="ide-refresh-tree" title="Refresh"></i></div>';
            document.getElementById('ide-file-tree').classList.remove('d-none');
          } else if (view === 'history') {
            document.getElementById('ide-sidebar-title').innerHTML = '<span>FILE HISTORY</span>';
            document.getElementById('ide-history-tree').classList.remove('d-none');
          }
          setTimeout(() => aceEditor.resize(true), 50);
        };

        window.toggleIdeSidebar = () => {
          window.switchIdeSidebar('explorer');
        };

        window.toggleIdeTerminal = () => {
          if (bottomPanel.classList.contains('active')) {
            bottomPanel.classList.remove('active');
          } else {
            bottomPanel.classList.add('active');
            document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
            document.querySelector('.panel-tab[data-target="terminal"]').classList.add('active');
            document.querySelectorAll('.panel-pane').forEach(p => p.classList.remove('active'));
            document.getElementById('pane-terminal').classList.add('active');
          }
          setTimeout(() => aceEditor.resize(true), 50);
        };

        document.getElementById('act-settings').addEventListener('click', () => {
          const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ide-settings-modal'));
          document.getElementById('ide-setting-theme').value = localStorage.getItem('ide_theme') || "ace/theme/tomorrow_night_eighties";
          document.getElementById('ide-setting-indent').value = localStorage.getItem('ide_indent') || "2";
          document.getElementById('ide-setting-wrap').checked = localStorage.getItem('ide_wrap') === 'true';
          document.getElementById('ide-setting-autosave').checked = localStorage.getItem('ide_autosave') !== 'false';
          document.getElementById('ide-setting-show_wordcount').checked = localStorage.getItem('ide_show_wordcount') === 'true';
          document.getElementById('ide-setting-show_charcount').checked = localStorage.getItem('ide_show_charcount') === 'true';
          
          const cliToggle = document.getElementById('ide-setting-disable-cli');
          if (cliToggle) {
            cliToggle.checked = localStorage.getItem('ide_disable_cli') === 'true';
          }

          const currentFontSize = localStorage.getItem('ide_fontsize') || '14';
          const fontInput = document.getElementById('ide-setting-fontsize');
          const fontVal = document.getElementById('ide-setting-fontsize-val');
          if (fontInput) fontInput.value = currentFontSize;
          if (fontVal) fontVal.innerText = currentFontSize + 'px';

          modal.show();
        });

        const cliToggle = document.getElementById('ide-setting-disable-cli');
        if (cliToggle) {
          cliToggle.addEventListener('change', async (e) => {
            localStorage.setItem('ide_disable_cli', e.target.checked.toString());
            await driveFetch('toggle_cli', { disable: e.target.checked });
          });
        }

        const sidebarResizer = document.getElementById('ide-sidebar-resizer');
        const mainSidebar = document.getElementById('ide-main-sidebar');
        let isResizingSidebar = false;
        if (sidebarResizer) {
          sidebarResizer.addEventListener('mousedown', (e) => {
            isResizingSidebar = true;
            sidebarResizer.classList.add('resizing');
            document.body.style.cursor = 'col-resize';
            e.preventDefault();
          });
          document.addEventListener('mousemove', (e) => {
            if (!isResizingSidebar) return;
            const newW = e.clientX - mainSidebar.getBoundingClientRect().left;
            if (newW > 150 && newW < 600) {
              mainSidebar.style.width = newW + 'px';
              aceEditor.resize(true);
            }
          });
          document.addEventListener('mouseup', () => {
            if (isResizingSidebar) {
              isResizingSidebar = false;
              sidebarResizer.classList.remove('resizing');
              document.body.style.cursor = 'default';
              aceEditor.resize(true);
            }
          });
        }

        const resizer = document.getElementById('ide-panel-resizer');
        let isResizing = false;
        if (resizer) {
          resizer.addEventListener('mousedown', (e) => {
            isResizing = true;
            resizer.classList.add('resizing');
            document.body.style.cursor = 'ns-resize';
            e.preventDefault();
          });
          document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            const containerH = document.querySelector('.ide-body').offsetHeight;
            const newH = document.body.clientHeight - e.clientY;
            if (newH > 35 && newH < containerH - 50) {
              bottomPanel.style.height = newH + 'px';
              aceEditor.resize(true);
            }
          });
          document.addEventListener('mouseup', () => {
            if (isResizing) {
              isResizing = false;
              resizer.classList.remove('resizing');
              document.body.style.cursor = 'default';
              aceEditor.resize(true);
            }
          });
        }

        const ideSidebar = document.getElementById('ide-main-sidebar');
        if (ideSidebar) {
          const scanIdeDroppedItems = async (items) => {
            const files = [];
            const paths = [];

            const readAllEntries = async (dirReader) => {
              let allEntries = [];
              const read = async () => {
                const entries = await new Promise((resolve) => dirReader.readEntries(resolve));
                if (entries && entries.length > 0) {
                  allEntries = allEntries.concat(entries);
                  await read();
                }
              };
              await read();
              return allEntries;
            };

            const traverseEntry = async (entry, path = '') => {
              if (entry.isFile) {
                const file = await new Promise((resolve) => entry.file(resolve));
                files.push(file);
                paths.push(path + file.name);
              } else if (entry.isDirectory) {
                const dirReader = entry.createReader();
                const entries = await readAllEntries(dirReader);
                for (const childEntry of entries) {
                  await traverseEntry(childEntry, path + entry.name + '/');
                }
              }
            };

            for (let i = 0; i < items.length; i++) {
              const entry = items[i].webkitGetAsEntry();
              if (entry) await traverseEntry(entry);
            }

            return { files, paths };
          };

          ideSidebar.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            ideSidebar.style.outline = '2px dashed #ff0000';
            ideSidebar.style.outlineOffset = '-4px';
            ideSidebar.style.backgroundColor = 'rgba(255, 0, 0, 0.08)';
          });

          ['dragleave', 'dragend'].forEach(evt => {
            ideSidebar.addEventListener(evt, (e) => {
              e.preventDefault();
              e.stopPropagation();
              ideSidebar.style.outline = 'none';
              ideSidebar.style.backgroundColor = '#0a0a0a';
            });
          });

          ideSidebar.addEventListener('drop', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            ideSidebar.style.outline = 'none';
            ideSidebar.style.backgroundColor = '#0a0a0a';

            if (e.dataTransfer.items && e.dataTransfer.items.length) {
              termLog('Scanning dropped items...');
              const { files, paths } = await scanIdeDroppedItems(e.dataTransfer.items);
              if (files.length > 0) {
                await window.ideChunkedUpload(files, paths, window.currentIdeTreePath || '');
              }
            }
          });
        }

        document.getElementById('ide-find-btn').addEventListener('click', () => {
          if (aceEditor) aceEditor.execCommand('find');
        });

        window.updateIdeOutputPreview = () => {
          if (!previewIframe) return;
          if (!currentPath) {
            previewIframe.removeAttribute('src');
            previewIframe.srcdoc = '<!DOCTYPE html><html data-bs-theme="dark"><body style="background:#0a0a0a;color:#888;font-family:sans-serif;padding:20px;text-align:center;">No file selected for preview.</body></html>';
            return;
          }
          const file = openFiles.find(f => f.path === currentPath);
          const ext = file ? file.ext.toLowerCase() : currentPath.split('.').pop().toLowerCase();

          if (ext === 'md' || ext === 'markdown') {
            const content = aceEditor.getValue();
            let parsed = content;
            if (typeof marked !== 'undefined') {
              try {
                marked.use({ gfm: true, breaks: true });
                parsed = marked.parse(content);
              } catch (e) {}
            }
            previewIframe.removeAttribute('src');
            previewIframe.srcdoc = `<!DOCTYPE html><html lang="en"><head><style>body{background-color:#0d1117;color:#c9d1d9;font-family:sans-serif;padding:32px;}</style></head><body>${parsed}</body></html>`;
          } else if (['html', 'htm'].includes(ext)) {
            const content = aceEditor.getValue();
            previewIframe.removeAttribute('src');
            previewIframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-same-origin allow-popups');
            previewIframe.srcdoc = content;
          } else if (['php'].includes(ext)) {
            previewIframe.removeAttribute('srcdoc');
            previewIframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-same-origin allow-popups');
            previewIframe.src = './' + currentPath + '?XDEBUG_SESSION_START=IDE&t=' + Date.now();
          } else if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(ext)) {
            const streamUrl = `?api=true&action=stream&file=${encodeURIComponent(currentPath)}`;
            previewIframe.removeAttribute('src');
            previewIframe.srcdoc = `<!DOCTYPE html><html><body style="background:#0a0a0a;margin:0;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="${streamUrl}" style="max-width:90%;max-height:90%;object-fit:contain;"></body></html>`;
          } else {
            const content = aceEditor.getValue();
            const escapeHtml = (str) => (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
            previewIframe.removeAttribute('src');
            previewIframe.srcdoc = `<!DOCTYPE html><html data-bs-theme="dark"><head><style>body{background:#0a0a0a;color:#f8f8f2;font-family:monospace;padding:16px;margin:0;}pre{white-space:pre-wrap;word-break:break-all;margin:0;}</style></head><body><pre>${escapeHtml(content)}</pre></body></html>`;
          }
        };

        document.getElementById('ide-preview-btn').addEventListener('click', () => {
          bottomPanel.classList.add('active');
          document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
          document.querySelector('.panel-tab[data-target="output"]').classList.add('active');
          document.querySelectorAll('.panel-pane').forEach(p => p.classList.remove('active'));
          document.getElementById('pane-output').classList.add('active');
          window.updateIdeOutputPreview();
        });

        document.querySelectorAll('.panel-tab').forEach(tab => {
          tab.addEventListener('click', () => {
            document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.querySelectorAll('.panel-pane').forEach(p => p.classList.remove('active'));
            document.getElementById(`pane-${tab.dataset.target}`).classList.add('active');
          });
        });

        document.getElementById('panel-close').addEventListener('click', () => {
          bottomPanel.classList.remove('active');
        });

        document.getElementById('panel-fullscreen').addEventListener('click', (e) => {
          bottomPanel.classList.toggle('fullscreen');
          e.target.className = bottomPanel.classList.contains('fullscreen') ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
          setTimeout(() => aceEditor.resize(true), 50);
        });

        document.getElementById('panel-reload')?.addEventListener('click', () => {
          if (previewIframe) window.updateIdeOutputPreview();
        });

        document.getElementById('panel-clear').addEventListener('click', () => {
          document.getElementById('terminal-logs').innerHTML = '';
        });

        document.getElementById('panel-newtab')?.addEventListener('click', () => {
          const win = window.open('about:blank', '_blank');
          if (win) {
            if (previewIframe.srcdoc) {
              win.document.open();
              win.document.write(previewIframe.srcdoc);
              win.document.close();
            } else if (previewIframe.src && !previewIframe.src.endsWith('about:blank')) {
              win.location.href = previewIframe.src;
            } else if (currentPath) {
              win.location.href = './' + currentPath;
            }
          }
        });

        document.getElementById('panel-eruda')?.addEventListener('click', () => {
          if (!bottomPanel.classList.contains('active')) {
            bottomPanel.classList.add('active');
          }
          document.querySelectorAll('.panel-tab').forEach(t => t.classList.remove('active'));
          const outputTab = document.querySelector('.panel-tab[data-target="output"]');
          if (outputTab) outputTab.classList.add('active');
          document.querySelectorAll('.panel-pane').forEach(p => p.classList.remove('active'));
          const paneOutput = document.getElementById('pane-output');
          if (paneOutput) paneOutput.classList.add('active');

          if (!previewIframe.contentWindow) return;
          const doc = previewIframe.contentWindow.document;
          if (doc.getElementById('eruda')) {
            previewIframe.contentWindow.eruda.show();
            return;
          }
          const script = doc.createElement('script');
          script.src = 'https://cdn.jsdelivr.net/npm/eruda';
          script.onload = () => {
            const initScript = doc.createElement('script');
            initScript.innerHTML = 'eruda.init(); eruda.show();';
            doc.body.appendChild(initScript);
          };
          doc.head.appendChild(script);
        });

        const termInput = document.getElementById('ide-terminal-input');
        if (termInput) {
          termInput.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter' && termInput.value.trim() !== '') {
              const cmd = termInput.value.trim();
              termLog(`<span class="text-success">~$</span> ${cmd}`);
              termInput.value = '';
              if (cmd === 'clear') {
                document.getElementById('terminal-logs').innerHTML = '';
              } else {
                try {
                  const res = await driveFetch('terminal_cmd', {
                    action: 'terminal_cmd',
                    cmd
                  }, window.currentIdeTreePath || '');
                  if (res && res.success && res.output) termLog(res.output);
                  else termLog(`<span class="text-danger">${res.output || 'Command failed'}</span>`);
                } catch (err) {
                  termLog(`<span class="text-danger">Failed to execute command.</span>`);
                }
              }
            }
          });
        }

        const updateFontSize = (newSize) => {
          const size = Math.max(10, Math.min(36, parseInt(newSize, 10)));
          localStorage.setItem('ide_fontsize', size.toString());
          document.getElementById('ide-setting-fontsize-val').innerText = size + 'px';
          aceEditor.setOption('fontSize', size + 'px');
        };

        document.getElementById('ide-setting-fontsize').addEventListener('input', (e) => updateFontSize(e.target.value));
        document.getElementById('ide-fontsize-minus').addEventListener('click', () => {
          const current = parseInt(localStorage.getItem('ide_fontsize') || '14', 10);
          updateFontSize(current - 1);
        });
        document.getElementById('ide-fontsize-plus').addEventListener('click', () => {
          const current = parseInt(localStorage.getItem('ide_fontsize') || '14', 10);
          updateFontSize(current + 1);
        });

        ['theme', 'indent', 'wrap', 'autosave', 'show_wordcount', 'show_charcount'].forEach(key => {
          document.getElementById('ide-setting-' + key).addEventListener('change', (e) => {
            const val = e.target.type === 'checkbox' ? e.target.checked.toString() : e.target.value;
            localStorage.setItem('ide_' + key, val);
            if (key === 'theme') aceEditor.setTheme(val);
            if (key === 'wrap') aceEditor.setOption('wrap', val === 'true');
            if (key === 'indent') {
              aceEditor.session.setTabSize(val === 'tab' ? 4 : parseInt(val));
              aceEditor.session.setUseSoftTabs(val !== 'tab');
              updateIDEStatusBar();
            }
            if (key === 'show_wordcount' || key === 'show_charcount') {
              updateIDEStatusBar();
            }
          });
        });

        loadTree();
        if (openFiles.length > 0) {
          renderTabs();
          if (activeTabPath) window.ideOpenTab(activeTabPath);
          else window.ideOpenTab(openFiles[0].path);
        }
      })();
    </script>
  </body>
</html>