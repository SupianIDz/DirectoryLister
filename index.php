<?php

class DirectoryLister
{
    /**
     * @var string|mixed
     */
    public string $currentPath;

    private array $excludedItems = [
        '.git', '.svn', '.htaccess', '.env', '.DS_Store',
        'Thumbs.db', '.gitignore', '.gitkeep', '.vscode',
        'node_modules', 'vendor', '.idea', 'index.php',
    ];

    private string|false $basePath;

    /**
     * @param  string $basePath
     */
    public function __construct(string $basePath = '../storage')
    {
        $this->basePath = realpath($basePath);
        $this->currentPath = $_GET['path'] ?? '';

        // security check: ensure user cannot exit from storage folder
        if (! $this->isPathSafe()) {
            $this->currentPath = '';
        }
    }

    /**
     * security check: ensure path does not exit from storage folder
     */
    private function isPathSafe() : bool
    {
        if (empty($this->currentPath)) {
            return true;
        }

        // check if there's attempt to exit from storage (../ etc)
        if (str_contains($this->currentPath, '..')) {
            return false;
        }

        // build full path and ensure still within storage folder
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $this->currentPath;
        $realFullPath = realpath($fullPath);

        // if path is invalid or doesn't exist
        if ($realFullPath === false) {
            return false;
        }

        // ensure real path is still within base path (storage)
        $realBasePath = realpath($this->basePath);
        if (! str_starts_with($realFullPath, $realBasePath)) {
            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    public function getCurrentDirectory() : string
    {
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . $this->currentPath;
        $realPath = realpath($fullPath);

        // double check security
        if ($realPath === false || ! $this->isPathWithinStorage($realPath)) {
            return $this->basePath;
        }

        return $realPath;
    }

    /**
     * check if path is still within storage folder
     */
    private function isPathWithinStorage(string $path) : bool
    {
        $realBasePath = realpath($this->basePath);

        return str_starts_with($path, $realBasePath);
    }

    /**
     * @return array
     */
    public function getDirectoryContents() : array
    {
        $directory = $this->getCurrentDirectory();

        if (! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $items = [];
        $files = scandir($directory);

        foreach ($files as $file) {
            if ($file === '.' || in_array($file, $this->excludedItems)) {
                continue;
            }

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;
            $relativePath = $this->currentPath ? $this->currentPath . '/' . $file : $file;

            // security check for each item
            if (is_dir($fullPath)) {
                $testPath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
                if (! $this->isPathWithinStorage(realpath($testPath))) {
                    continue; // skip folder that exits from storage
                }
            }

            $items[] = [
                'name'        => $file,
                'path'        => $relativePath,
                'isDirectory' => is_dir($fullPath),
                'size'        => $this->getSize($fullPath),
                'modified'    => filemtime($fullPath),
                'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4),
            ];
        }

        // sort: directories first, then files
        usort($items, function ($a, $b) {
            if ($a['isDirectory'] !== $b['isDirectory']) {
                return $b['isDirectory'] - $a['isDirectory'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * @param  string $path
     * @return int
     */
    private function getSize(string $path) : int
    {
        if (is_file($path)) {
            return filesize($path);
        } else if (is_dir($path)) {
            return $this->getDirectorySize($path);
        }

        return 0;
    }

    /**
     * @param  string $directory
     * @return int
     */
    private function getDirectorySize(string $directory) : int
    {
        $size = 0;
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                $size += $file->getSize();
            }
        } catch (Exception $e) {
            // if there's error (permission denied, etc), return 0
            return 0;
        }

        return $size;
    }

    /**
     * @param $size
     * @return string
     */
    public function formatSize($size) : string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * @return array[]
     */
    public function getBreadcrumbs() : array
    {
        if (empty($this->currentPath)) {
            return [['name' => 'storage', 'path' => '']];
        }

        $breadcrumbs = [['name' => 'storage', 'path' => '']];
        $pathParts = explode('/', $this->currentPath);
        $currentPath = '';

        foreach ($pathParts as $part) {
            $currentPath .= ($currentPath ? '/' : '') . $part;

            // security check for each breadcrumb
            $testFullPath = $this->basePath . DIRECTORY_SEPARATOR . $currentPath;
            if (! $this->isPathWithinStorage(realpath($testFullPath))) {
                break; // stop if exits from storage
            }

            $breadcrumbs[] = ['name' => $part, 'path' => $currentPath];
        }

        return $breadcrumbs;
    }

    /**
     * @return string
     */
    public function getParentPath() : string
    {
        if (empty($this->currentPath)) {
            return '';
        }

        $pathParts = explode('/', $this->currentPath);
        array_pop($pathParts);
        $parentPath = implode('/', $pathParts);

        // security check for parent path
        if (! empty($parentPath)) {
            $testFullPath = $this->basePath . DIRECTORY_SEPARATOR . $parentPath;
            if (! $this->isPathWithinStorage(realpath($testFullPath))) {
                return ''; // return to storage root if parent exits from storage
            }
        }

        return $parentPath;
    }
}

$lister = new DirectoryLister();
$items = $lister->getDirectoryContents();
$breadcrumbs = $lister->getBreadcrumbs();
?>

<!DOCTYPE html>
<html lang="en" class="h-full">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Directory Lister</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400..700;1,400..700&display=swap" rel="stylesheet">

        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            'mono': ['Iosevka', 'ui-monospace', 'SFMono-Regular', 'Monaco', 'Consolas', 'Liberation Mono', 'Courier New', 'monospace'],
                        },
                    },
                },
                darkMode: 'class',
            };
        </script>
        <style>
            /* iosevka-latin-400-normal */
            @font-face {
                font-family: 'Iosevka';
                font-style: normal;
                font-display: swap;
                font-weight: 400;
                src: url(https://cdn.jsdelivr.net/fontsource/fonts/iosevka@latest/latin-400-normal.woff2) format('woff2'), url(https://cdn.jsdelivr.net/fontsource/fonts/iosevka@latest/latin-400-normal.woff) format('woff');
            }

            body {
                font-family: 'Iosevka', monospace;
            }

            .file-icon {
                transition: all 0.2s ease;
            }

            .file-row:hover .file-icon {
                transform: scale(1.1);
            }
        </style>
    </head>
    <body class="bg-slate-50 dark:bg-slate-900 min-h-full">
        <div class="min-h-screen">
            <!-- header -->
            <header class="bg-white dark:bg-slate-800 shadow-sm border-b border-slate-200 dark:border-slate-700">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-16">
                        <div class="flex items-center space-x-4">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-md flex items-center justify-center">
                                    <!-- lucide folder icon -->
                                    <svg class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>
                                    </svg>
                                </div>
                            </div>
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-white lowercase">storage browser</h1>
                        </div>

                        <div class="flex items-center space-x-2">
                            <!-- security indicator -->
                            <div class="hidden p-2 sm:flex items-center space-x-2  bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 rounded-md text-sm">
                                <!-- lucide shield-check icon -->
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/>
                                    <path d="m9 12 2 2 4-4"/>
                                </svg>
                                <span class="uppercase">secure</span>
                            </div>

                            <button onclick="toggleDarkMode()"
                                    class="p-2 rounded-md bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors">
                                <!-- lucide sun icon -->
                                <svg class="size-5 hidden dark:block" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="4"/>
                                    <path d="M12 2v2"/>
                                    <path d="M12 20v2"/>
                                    <path d="m4.93 4.93 1.41 1.41"/>
                                    <path d="m17.66 17.66 1.41 1.41"/>
                                    <path d="M2 12h2"/>
                                    <path d="M20 12h2"/>
                                    <path d="m6.34 17.66-1.41 1.41"/>
                                    <path d="m19.07 4.93-1.41 1.41"/>
                                </svg>
                                <!-- lucide moon icon -->
                                <svg class="size-5 block dark:hidden" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <!-- main content -->
            <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <!-- breadcrumb -->
                <nav class="flex mb-6" aria-label="breadcrumb">
                    <ol class="inline-flex items-center space-x-1 md:space-x-3">
                        <?php foreach ($breadcrumbs as $index => $crumb): ?>
                            <li class="inline-flex items-center">
                                <?php if ($index > 0): ?>
                                    <!-- lucide chevron-right icon -->
                                    <svg class="w-4 h-4 text-slate-400 mx-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m9 18 6-6-6-6"/>
                                    </svg>
                                <?php endif; ?>
                                <?php if ($index === count($breadcrumbs) - 1): ?>
                                    <span class="text-slate-500 dark:text-slate-400 font-medium lowercase"><?= htmlspecialchars(strtolower($crumb['name'])) ?></span>
                                <?php else: ?>
                                    <a href="?path=<?= urlencode($crumb['path']) ?>"
                                       class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium transition-colors lowercase">
                                        <?= htmlspecialchars(strtolower($crumb['name'])) ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>

                <!-- back button -->
                <?php if (!empty($lister->currentPath)): ?>
                    <div class="mb-4">
                        <a href="?path=<?= urlencode($lister->getParentPath()) ?>"
                           class="inline-flex items-center px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-md hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors lowercase">
                            <!-- lucide arrow-left icon -->
                            <svg class="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m12 19-7-7 7-7"/>
                                <path d="M19 12H5"/>
                            </svg>
                            back
                        </a>
                    </div>
                <?php endif; ?>

                <!-- file list -->
                <div class="bg-white dark:bg-slate-800 shadow-sm rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white lowercase">contents</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 lowercase"><?= count($items) ?> items</p>
                    </div>

                    <?php if (empty($items)): ?>
                        <div class="px-6 py-12 text-center">
                            <!-- lucide trash-2 icon -->
                            <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h18"/>
                                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                                <line x1="10" x2="10" y1="11" y2="17"/>
                                <line x1="14" x2="14" y1="11" y2="17"/>
                            </svg>
                            <h3 class="text-lg font-medium text-slate-900 dark:text-white mb-2 lowercase">empty folder</h3>
                            <p class="text-slate-500 dark:text-slate-400 lowercase">no files or folders in this directory.</p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php foreach ($items as $item): ?>
                                <div class="file-row px-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors cursor-pointer"
                                     onclick="<?= $item['isDirectory'] ? "window.location.href='?path=" . urlencode($item['path']) . "'" : "downloadFile('" . htmlspecialchars($item['name']) . "')" ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4 flex-1 min-w-0">
                                            <div class="file-icon flex-shrink-0">
                                                <?php if ($item['isDirectory']): ?>
                                                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-md flex items-center justify-center">
                                                        <!-- lucide folder icon -->
                                                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>
                                                        </svg>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-10 h-10 bg-slate-100 dark:bg-slate-700 rounded-md flex items-center justify-center">
                                                        <?php
                                                        $extension = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
                                                        $iconClass = "w-6 h-6 text-slate-600 dark:text-slate-400";

                                                        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                                                            // lucide image icon
                                                            echo '<svg class="' . $iconClass . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
                                                        } else if (in_array($extension, ['pdf'])) {
                                                            // lucide file-text icon
                                                            echo '<svg class="' . $iconClass . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>';
                                                        } else if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
                                                            // lucide archive icon
                                                            echo '<svg class="' . $iconClass . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>';
                                                        } else {
                                                            // lucide file icon
                                                            echo '<svg class="' . $iconClass . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>';
                                                        }
                                                        ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center space-x-2">
                                                    <h3 class="text-sm font-medium text-slate-900 dark:text-white truncate">
                                                        <?= htmlspecialchars($item['name']) ?>
                                                    </h3>
                                                    <?php if ($item['isDirectory']): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 lowercase">
                                                            folder
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 lowercase">
                                                    modified: <?= date('d M Y H:i', $item['modified']) ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex items-center space-x-4 text-sm text-slate-500 dark:text-slate-400">
                                            <div class="text-right">
                                                <div class="font-medium">
                                                    <?= $lister->formatSize($item['size']) ?>
                                                </div>
                                                <div class="text-xs font-mono">
                                                    <?= $item['permissions'] ?>
                                                </div>
                                            </div>

                                            <?php if ($item['isDirectory']): ?>
                                                <!-- lucide chevron-right icon -->
                                                <svg class="size-5 text-slate-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m9 18 6-6-6-6"/>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>

        <script>
            // Dark mode toggle
            function toggleDarkMode() {
                const html = document.documentElement;
                const isDark = html.classList.contains('dark');

                if (isDark) {
                    html.classList.remove('dark');
                    localStorage.setItem('darkMode', 'false');
                } else {
                    html.classList.add('dark');
                    localStorage.setItem('darkMode', 'true');
                }
            }

            // Initialize dark mode from localStorage
            function initDarkMode() {
                const darkMode = localStorage.getItem('darkMode');
                if (darkMode === 'true' || (! darkMode && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            }

            // File download handler
            function downloadFile(filename) {
                // Implement file download logic here
                console.log('Download file:', filename);
                // You can add actual download functionality here
            }

            // Keyboard navigation
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    // Go back to parent directory if possible
                    const backButton = document.querySelector('a[href*="path="]');
                    if (backButton && backButton.textContent.includes('Back')) {
                        window.location.href = backButton.href;
                    }
                }
            });

            // Initialize
            initDarkMode();

            // Add loading states for navigation
            document.addEventListener('click', function (e) {
                const link = e.target.closest('a[href*="path="]');
                if (link) {
                    link.style.opacity = '0.6';
                    link.style.pointerEvents = 'none';
                }
            });
        </script>
    </body>
</html>