# PHPEditor

<img width="1366" height="768" alt="PHPEditor" src="https://github.com/user-attachments/assets/ece8c3c8-5850-4fed-b87f-a254dce1c15c" />

A lightweight, single-file, web-based IDE and file manager. Built with PHP and Ace Editor, it allows you to manage files, write code, and preview your work directly from the browser.

## Features

* **File Management:** Create, rename, delete, and upload files or folders.
* **Code Editor:** Powered by Ace Editor with syntax highlighting, customizable themes, font scaling, and autosave.
* **Live Preview:** Instant HTML/Markdown rendering, image/video viewing, and PHP execution.
* **Developer Tools:** Integrated Eruda console for debugging and a built-in terminal for running server-side shell commands.
* **Standalone:** No database or complex setup required. Everything runs from a single PHP file.

## Usage

1. Place the `phpeditor.php` (or `index.php`) file into your local server directory (e.g., XAMPP, Laragon, or any PHP environment).
2. Open the file via your web browser (`http://localhost/phpeditor.php`).

## ⚠️ Security Warning
This application is designed for **local development only**. It grants unrestricted read, write, and command-line execution access to your server. **Do not deploy this file on a public or production server** without implementing strict authentication (e.g., HTTP Basic Auth) and securing the directory.
