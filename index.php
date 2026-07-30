<?php
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

function formatBytes($bytes, $precision = 2) {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= (1 << (10 * $pow));
  return round($bytes, $precision) . ' ' . $units[$pow];
}

if (isset($_GET['api'])) {
  $action = $_GET['action'] ?? '';
  
  if ($action !== 'stream') {
    header('Content-Type: application/json');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $postAction = $input['action'] ?? $action;
    $absPath = $baseDir;

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
          mkdir($full);
          echo json_encode(['success' => true]);
          break;
        case 'write':
          $file = $input['file'] ?? '';
          $content = $input['content'] ?? '';
          $full = $baseDir . '/' . $file;
          if (!isValidPath($baseDir, $full)) throw new Exception('Invalid file');
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
        case 'zip_items':
          $items = $input['items'] ?? [];
          if (empty($items)) throw new Exception('No items selected');
          $zipName = 'Archive_' . date('Ymd_His') . '.zip';
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
        case 'terminal_cmd':
          $cmd = $input['cmd'] ?? '';
          $output = [];
          if (preg_match('/[;|&`\n$]/', $cmd) || strpos($cmd, '>') !== false || strpos($cmd, '<') !== false || preg_match('/(rm\s+-rf|curl|wget|nc|bash|sh)/i', $cmd)) {
            echo json_encode(['success' => false, 'output' => "Command restricted. Chaining, downloading, and destructive commands are forbidden."]);
          } elseif (preg_match('/^(git|ls|pwd|whoami|echo|php -v|cat|top)\b/i', $cmd)) {
            exec($cmd . ' 2>&1', $output);
            echo json_encode(['success' => true, 'output' => htmlspecialchars(implode("\n", $output))]);
          } else {
            echo json_encode(['success' => false, 'output' => "Command restricted. Only safe commands allowed (git, ls, pwd, php -v, etc)."]);
          }
          break;
        case 'upload':
          $uploaded = 0;
          if (isset($_FILES['files'])) {
            foreach ($_FILES['files']['name'] as $i => $name) {
              $dest = $absPath . '/' . $name;
              if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) $uploaded++;
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
            $iter = new RecursiveIteratorIterator(
              new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
              RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iter as $item) {
              $pathName = $item->getPathname();
              if (strpos($pathName, '.git') !== false) continue;
              $filename = $item->getFilename();
              if (stripos($filename, $q) !== false) {
                $rel = ltrim(str_replace($baseDir, '', $pathName), '/');
                $rel = str_replace('\\', '/', $rel);
                $isDir = $item->isDir();
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $meta = [
                  'name' => $rel,
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
          $items = array_diff(scandir($absPath), ['.', '..', '.git']);
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
          $mime = mime_content_type($full) ?: 'application/octet-stream';
          $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
          if ($ext === 'css') $mime = 'text/css';
          if ($ext === 'js') $mime = 'application/javascript';
          if ($ext === 'svg') $mime = 'image/svg+xml';
          if ($ext === 'html' || $ext === 'htm' || $ext === 'php') $mime = 'text/html';
          header('Content-Type: ' . $mime);
          readfile($full);
          break;
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

      .ide-tree-item.active {
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

      <div class="ide-ctx-modal" id="ide-settings-modal" style="width: 300px">
        <div class="ide-ctx-title fw-bold">IDE Settings</div>
        <div class="p-2 text-white" style="font-size: 0.85rem">
          <div class="mb-2">
            <label class="form-label mb-1">Theme</label>
            <select id="ide-setting-theme" class="form-select form-select-sm bg-dark text-white border-secondary">
              <option value="ace/theme/chaos">Chaos</option>
              <option value="ace/theme/dracula">Dracula</option>
              <option value="ace/theme/monokai">Monokai</option>
              <option value="ace/theme/github_dark">GitHub Dark</option>
              <option value="ace/theme/tomorrow_night_eighties">
                Tomorrow Night 80s
              </option>
              <option value="ace/theme/twilight">Twilight</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label mb-1">Indentation</label>
            <select id="ide-setting-indent" class="form-select form-select-sm bg-dark text-white border-secondary">
              <option value="2">2 Spaces</option>
              <option value="4">4 Spaces</option>
              <option value="tab">Tabs</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label mb-1 d-flex justify-content-between align-items-center">
              <span>Font Size</span>
              <span id="ide-setting-fontsize-val" class="text-info fw-bold">14px</span>
            </label>
            <input type="range" class="form-range" id="ide-setting-fontsize" min="10" max="36" step="1" value="14" />
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="ide-setting-wrap" />
            <label class="form-check-label">Word Wrap</label>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="ide-setting-autosave" />
            <label class="form-check-label">Auto Save (Every 10s)</label>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="ide-setting-show_wordcount" />
            <label class="form-check-label">Show Word Count</label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="ide-setting-show_charcount" />
            <label class="form-check-label">Show Character Count</label>
          </div>
          <button class="btn btn-sm btn-outline-light w-100" onclick="document.getElementById('ide-settings-modal').style.display='none'">
            Close
          </button>
        </div>
      </div>

      <div class="ide-body">
        <div class="ide-activity-bar">
          <div class="ide-activity-action active" id="act-explorer" title="Files / Explorer" onclick="window.toggleIdeSidebar()">
            <i class="bi bi-files"></i>
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

          <div class="ide-file-tree" id="ide-file-tree">
            <div class="text-center mt-4 text-secondary">
              <i class="spinner-border spinner-border-sm"></i> Loading...
            </div>
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
          
          <!-- Terminal / Output Panel inside Editor Wrapper so it widens naturally -->
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
        const mediaExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mp3', 'wav', 'ogg'];

        const termLog = (msg, isError = false) => {
          const logs = document.getElementById('terminal-logs');
          if (logs) {
            logs.innerHTML += `<div class="${isError ? 'text-danger' : 'text-light'}">${msg}</div>`;
            logs.scrollTop = logs.scrollHeight;
          }
        };

        const driveFetch = async (action, body = null) => {
          let url = `?api=true&action=${action}`;
          let options = {};
          if (body) {
            if (body instanceof FormData) {
              options = {
                method: 'POST',
                body: body
              };
            } else {
              options = {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
              };
            }
          }
          const res = await fetch(url, options);
          return res.json();
        };

        const loadTree = async (path = '') => {
          try {
            const data = await driveFetch(`list&path=${encodeURIComponent(path)}`);
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
            el.addEventListener('click', () => loadTree(el.dataset.path));
            el.addEventListener('contextmenu', (e) => {
              e.preventDefault();
              window.showIdeContextMenu(el.dataset.path, el.dataset.name, true);
            });
          });

          treeEl.querySelectorAll('.ide-file-item').forEach(el => {
            el.addEventListener('click', () => {
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

            if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(file.ext)) {
              mediaContent.innerHTML = `<img src="${streamUrl}" style="max-width: 100%; max-height: 100%; object-fit: contain;">`;
            } else if (['mp4', 'webm'].includes(file.ext)) {
              mediaContent.innerHTML = `<video src="${streamUrl}" controls style="max-width: 100%; max-height: 100%; outline: none;"></video>`;
            } else {
              mediaContent.innerHTML = `<i class="bi bi-music-note-beamed text-danger mb-3" style="font-size: 4rem;"></i><audio src="${streamUrl}" controls style="width: 300px; outline: none;"></audio>`;
            }

            let displaySize = file.formatSize;
            if (!displaySize && file.size && !isNaN(parseInt(file.size)) && parseInt(file.size) > 0) {
              const s = parseInt(file.size);
              if (s < 1024) displaySize = s + ' B';
              else if (s < 1024 * 1024) displaySize = (s / 1024).toFixed(2) + ' KB';
              else displaySize = (s / (1024 * 1024)).toFixed(2) + ' MB';
            }
            document.getElementById('ide-media-info').innerHTML = `${path} <br> Size: ${displaySize || 'Unknown'}`;
            updateIDEStatusBar();
            termLog(`Opened media file: ${path}`);
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
            if (file.size > 1.0 * 1024 * 1024) {
              aceEditor.session.setUseWorker(false);
              try {
                aceEditor.setOptions({
                  enableBasicAutocompletion: false,
                  enableLiveAutocompletion: false,
                  wrap: false,
                  foldStyle: 'manual'
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
                  const frameworkCompleter = {
                    getCompletions: function(editor, session, pos, prefix, callback) {
                      const completions = [
                        {caption: "Route::get", value: "Route::get('/${1:path}', function () {\n    return view('${2:view}');\n});", meta: "Laravel"},
                        {caption: "Route::post", value: "Route::post('/${1:path}', [${2:Controller}::class, '${3:method}']);", meta: "Laravel"},
                        {caption: "public function", value: "public function ${1:name}()\n{\n    ${2}\n}", meta: "PHP"},
                        {caption: "$this->render", value: "$this->render('${1:template.html.twig}', [\n    '${2:var}' => $${3:val},\n]);", meta: "Symfony"},
                        {caption: "dd()", value: "dd($${1:var});", meta: "Debug"},
                        {caption: "dump()", value: "dump($${1:var});", meta: "Debug"},
                        {caption: "Log::info", value: "Log::info('${1:message}', ['${2:context}' => $${3:var}]);", meta: "Laravel"}
                      ];
                      callback(null, completions);
                    }
                  };
                  if (!langTools.getCompleters().some(c => c === frameworkCompleter)) {
                    langTools.addCompleter(frameworkCompleter);
                  }
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
                aceEditor.setValue(safeContent, -1);
                aceEditor.session.setMode(targetMode);
                setTimeout(() => {
                  aceEditor.resize(true);
                  aceEditor.clearSelection();
                }, 100);
                termLog(`Loaded ${safeContent.length} bytes.`);
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
          if (e) e.stopPropagation();
          if (localStorage.getItem('ide_autosave') !== 'false' && path === currentPath) {
            const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath}"] .tab-title`);
            if (activeTab && activeTab.innerText.endsWith(' *')) await window.saveCurrentFile(true);
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

        aceEditor.on("change", () => {
          const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath}"] .tab-title`);
          if (activeTab && !activeTab.innerText.endsWith(' *')) activeTab.innerText += ' *';
        });

        window.saveCurrentFile = async (silent = false) => {
          if (!currentPath) return;
          const file = openFiles.find(f => f.path === currentPath);
          if (file && mediaExts.includes(file.ext)) return;

          const btn = document.getElementById('ide-save-btn');
          const orig = btn.innerHTML;
          if (!silent) btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

          const content = aceEditor.getValue();
          const data = await driveFetch('write', {
            action: 'write',
            file: currentPath,
            content: content
          });
          if (data && data.success) {
            const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath}"] .tab-title`);
            if (activeTab && activeTab.innerText.endsWith(' *')) activeTab.innerText = activeTab.innerText.slice(0, -2);
            if (!silent) {
              btn.innerHTML = '<i class="bi bi-check"></i> Saved';
              btn.classList.add('text-success');
              termLog(`Saved ${currentPath} successfully.`);
              if (bottomPanel.classList.contains('active')) window.updateIdeOutputPreview();
              setTimeout(() => {
                btn.innerHTML = orig;
                btn.classList.remove('text-success');
              }, 2000);
            }
          } else {
            if (!silent) {
              btn.innerHTML = orig;
              termLog(`Save failed`, true);
            }
          }
        };

        setInterval(() => {
          if (localStorage.getItem('ide_autosave') !== 'false') {
            const activeTab = document.querySelector(`.ide-tab[data-path="${currentPath}"] .tab-title`);
            if (currentPath && activeTab && activeTab.innerText.endsWith(' *')) window.saveCurrentFile(true);
          }
        }, 10000);

        document.getElementById('ide-save-btn').onclick = () => window.saveCurrentFile(false);

        window.showIdeContextMenu = (path, name, isFolder) => {
          const modal = document.getElementById('ide-ctx-modal');
          document.getElementById('ide-ctx-title').innerText = name || '/ (Root)';
          document.getElementById('ide-ctx-path').value = path || '';
          document.getElementById('ide-ctx-is-folder').value = isFolder ? '1' : '0';

          const isRoot = path === '';
          document.getElementById('ide-btn-new-file').style.display = isFolder ? 'flex' : 'none';
          document.getElementById('ide-btn-new-folder').style.display = isFolder ? 'flex' : 'none';
          document.getElementById('ide-btn-rename').style.display = isRoot ? 'none' : 'flex';
          document.getElementById('ide-btn-delete').style.display = isRoot ? 'none' : 'flex';
          const zipBtn = document.getElementById('ide-btn-zip');
          if (zipBtn) zipBtn.style.display = isRoot ? 'none' : 'flex';
          const unzipBtn = document.getElementById('ide-btn-unzip');
          if (unzipBtn) unzipBtn.style.display = (!isFolder && path.endsWith('.zip')) ? 'flex' : 'none';
          modal.style.display = 'flex';
        };

        const handleUploadClick = (targetPath) => {
          const fileInput = document.createElement('input');
          fileInput.type = 'file';
          fileInput.multiple = true;
          fileInput.onchange = async (e) => {
            if (e.target.files.length > 0) {
              const fd = new FormData();
              fd.append('action', 'upload');
              for (let i = 0; i < e.target.files.length; i++) {
                fd.append('files[]', e.target.files[i]);
              }
              const res = await driveFetch(`upload&path=${encodeURIComponent(targetPath)}`, fd);
              if (res.success) loadTree(targetPath);
              else alert(res.error || 'Upload failed');
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
            loadTree();
            return;
          }
          ideSearchTimeout = setTimeout(async () => {
            const treeEl = document.getElementById('ide-file-tree');
            if (!treeEl) return;
            treeEl.innerHTML = '<div class="text-center mt-4 text-secondary"><i class="spinner-border spinner-border-sm"></i> Searching entire workspace...</div>';
            try {
              const res = await fetch(`?api=true&action=search_drive&q=${encodeURIComponent(q)}`);
              if (!res.ok) throw new Error('Network error');
              const data = await res.json();
              if (data && data.success) {
                if (data.folders.length === 0 && data.files.length === 0) {
                  treeEl.innerHTML = '<div class="text-secondary p-2 text-center mt-3">No files found matching your search.</div>';
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

        document.getElementById('ide-tree-new-file').onclick = async () => {
          const name = prompt('Enter new file name:');
          if (name) {
            const res = await driveFetch('add_file', { action: 'add_file', name: name });
            if (res.success) loadTree();
            else alert(res.error);
          }
        };

        document.getElementById('ide-tree-new-folder').onclick = async () => {
          const name = prompt('Enter new folder name:');
          if (name) {
            const res = await driveFetch('add_folder', { action: 'add_folder', name: name });
            if (res.success) loadTree();
            else alert(res.error);
          }
        };

        document.getElementById('ide-tree-upload').onclick = () => handleUploadClick('');
        document.getElementById('ide-refresh-tree').onclick = () => loadTree();

        document.getElementById('ide-btn-new-file').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          const name = prompt('Enter new file name:');
          if (name) {
            const targetPath = (path ? path + '/' : '');
            const res = await driveFetch('add_file', { action: 'add_file', name: targetPath + name });
            if (res.success) loadTree();
            else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-new-folder').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          const name = prompt('Enter new folder name:');
          if (name) {
            const targetPath = (path ? path + '/' : '');
            const res = await driveFetch('add_folder', { action: 'add_folder', name: targetPath + name });
            if (res.success) loadTree();
            else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-rename').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          const name = prompt('Enter new name:');
          if (name && path) {
            const res = await driveFetch('rename', { action: 'rename', old: path, new: name });
            if (res.success) {
              const openFile = openFiles.find(f => f.path === path);
              if (openFile) {
                const newPath = path.substring(0, path.lastIndexOf('/') + 1) + name;
                openFile.path = newPath;
                openFile.name = name;
                openFile.ext = name.split('.').pop().toLowerCase();
                if (currentPath === path) currentPath = newPath;
                if (activeTabPath === path) activeTabPath = newPath;
                localStorage.setItem('ide_open_files', JSON.stringify(openFiles));
                localStorage.setItem('ide_active_tab', activeTabPath);
                renderTabs();
              }
              loadTree();
            } else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-zip').onclick = async () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          if (pathStr) {
            termLog(`Zipping item...`);
            const res = await driveFetch('zip_items', { action: 'zip_items', items: [pathStr] });
            if (res.success) { loadTree(); termLog('Zip successful.'); }
            else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-unzip').onclick = async () => {
          const pathStr = document.getElementById('ide-ctx-path').value;
          if (pathStr && pathStr.endsWith('.zip')) {
            termLog(`Extracting ${pathStr}...`);
            const res = await driveFetch('unzip', { action: 'unzip', item: pathStr });
            if (res.success) { loadTree(); termLog('Extraction successful.'); }
            else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        document.getElementById('ide-btn-upload').onclick = () => {
          const path = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          handleUploadClick(path);
        };

        document.getElementById('ide-btn-refresh').onclick = () => {
          const path = document.getElementById('ide-ctx-path').value;
          document.getElementById('ide-ctx-modal').style.display = 'none';
          loadTree(path);
        };

        document.getElementById('ide-btn-delete').onclick = async () => {
          const path = document.getElementById('ide-ctx-path').value;
          if (path && confirm('Are you sure you want to delete this?')) {
            const res = await driveFetch('delete', { action: 'delete', items: [path] });
            if (res.success) {
              const openFileIdx = openFiles.findIndex(f => f.path === path);
              if (openFileIdx !== -1) window.ideCloseTab(path, { stopPropagation: () => {} });
              loadTree();
            } else alert(res.error);
          }
          document.getElementById('ide-ctx-modal').style.display = 'none';
        };

        window.toggleIdeSidebar = () => {
          const sidebar = document.getElementById('ide-main-sidebar');
          const actionEl = document.getElementById('act-explorer');
          if (sidebar) {
            sidebar.classList.toggle('d-none');
            if (actionEl) actionEl.classList.toggle('active', !sidebar.classList.contains('d-none'));
            setTimeout(() => aceEditor.resize(true), 50);
          }
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
          const modal = document.getElementById('ide-settings-modal');
          document.getElementById('ide-setting-theme').value = localStorage.getItem('ide_theme') || "ace/theme/chaos";
          document.getElementById('ide-setting-indent').value = localStorage.getItem('ide_indent') || "2";
          document.getElementById('ide-setting-wrap').checked = localStorage.getItem('ide_wrap') === 'true';
          document.getElementById('ide-setting-autosave').checked = localStorage.getItem('ide_autosave') !== 'false';
          document.getElementById('ide-setting-show_wordcount').checked = localStorage.getItem('ide_show_wordcount') === 'true';
          document.getElementById('ide-setting-show_charcount').checked = localStorage.getItem('ide_show_charcount') === 'true';
          const currentFontSize = localStorage.getItem('ide_fontsize') || '14';
          document.getElementById('ide-setting-fontsize').value = currentFontSize;
          document.getElementById('ide-setting-fontsize-val').innerText = currentFontSize + 'px';
          modal.style.display = 'flex';
        });

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

        document.addEventListener('keydown', (e) => {
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            window.saveCurrentFile();
          }
        });

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
                  });
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