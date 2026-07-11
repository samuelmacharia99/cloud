<?php

namespace App\Services\SSH;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\Node;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Production-grade SSH service wrapper
 * Handles command execution, file uploads, and error handling
 */
class SSHService
{
    private ?SSH2 $ssh = null;

    private ?SFTP $sftp = null;

    private Node $node;

    private bool $connected = false;

    private int $timeout = 30; // default connection timeout

    public function __construct(Node $node)
    {
        $this->node = $node;
    }

    /**
     * Static factory method
     */
    public static function forNode(Node $node): self
    {
        return new self($node);
    }

    /**
     * Ensure connection is active
     */
    private function ensureConnected(): void
    {
        if (! $this->connected) {
            $this->connect();
        }
    }

    /**
     * Establish SSH connection
     */
    private function connect(): void
    {
        try {
            $this->ssh = new SSH2($this->node->ip_address, (int) $this->node->ssh_port);
            $this->ssh->setTimeout($this->timeout);

            $authenticated = false;

            // Try password authentication first
            // NOTE: $this->node->ssh_password is already decrypted by the Model's 'encrypted' cast
            // Do NOT call decrypt() on it again - that causes "payload is invalid" errors
            if ($this->node->ssh_password) {
                $authenticated = @$this->ssh->login(
                    $this->node->ssh_username,
                    $this->node->ssh_password
                );
            }

            // Fallback to login key if available and password failed
            // NOTE: $this->node->da_login_key is already decrypted by the Model's 'encrypted' cast
            if (! $authenticated && $this->node->da_login_key) {
                try {
                    $key = PublicKeyLoader::load($this->node->da_login_key);
                    $authenticated = @$this->ssh->login($this->node->ssh_username, $key);
                } catch (\Exception $e) {
                    throw new \Exception(
                        'SSH key format invalid: '.$e->getMessage()
                    );
                }
            }

            if (! $authenticated) {
                throw new \Exception('SSH authentication failed - invalid credentials or network issue');
            }

            $this->connected = true;
        } catch (\Exception $e) {
            throw new SSHConnectionException(
                $this->node->ip_address,
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Execute a remote command and return output
     */
    public function exec(string $command, int $timeout = 60): string
    {
        $this->ensureConnected();

        try {
            $this->ssh->setTimeout($timeout);
            $output = $this->ssh->exec($command);

            if ($this->ssh->getExitStatus() !== 0) {
                throw new SSHCommandException(
                    $command,
                    $output,
                    'Command exited with status '.$this->ssh->getExitStatus()
                );
            }

            return $output;
        } catch (SSHCommandException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new SSHCommandException(
                $command,
                '',
                $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Download a remote file to a local path (streamed; suitable for large archives).
     */
    public function downloadToLocal(string $remotePath, string $localPath): void
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            $directory = dirname($localPath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if ($this->sftp->get($remotePath, $localPath) === false) {
                throw new \Exception('File not found or read failed');
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to download {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Upload a local file to a remote path (streamed; suitable for large archives).
     */
    public function uploadFromLocal(string $localPath, string $remotePath): void
    {
        $this->ensureConnected();

        if (! is_readable($localPath)) {
            throw new \InvalidArgumentException("Local file is not readable: {$localPath}");
        }

        try {
            $this->initSFTP();

            $directory = dirname($remotePath);
            if ($directory !== '/') {
                $this->mkdirp($directory);
            }

            if (! $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                throw new \Exception("Failed to write file to {$remotePath}");
            }

            @$this->sftp->chmod(0644, $remotePath);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to upload {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Upload file content to remote server
     */
    public function upload(string $localContent, string $remotePath): void
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            // Create parent directory if needed
            $directory = dirname($remotePath);
            if ($directory !== '/') {
                $this->mkdirp($directory);
            }

            // Write file content
            if (! $this->sftp->put($remotePath, $localContent)) {
                throw new \Exception("Failed to write file to {$remotePath}");
            }

            // Make sure permissions are set correctly
            @$this->sftp->chmod(0644, $remotePath);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to upload {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Download file from remote server
     */
    public function downloadFile(string $remotePath): string
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            $content = $this->sftp->get($remotePath);
            if ($content === false) {
                throw new \Exception('File not found or read failed');
            }

            return $content;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to download {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create directory recursively (mkdir -p equivalent)
     */
    public function mkdirp(string $path): void
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            // Try to create, ignore if already exists
            @$this->sftp->mkdir($path, 0755, true);
        } catch (\Exception $e) {
            // Directory may already exist, which is fine
        }
    }

    /**
     * Check if file exists on remote server
     */
    public function fileExists(string $path): bool
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            return $this->sftp->file_exists($path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Rename a remote file or directory
     */
    public function rename(string $from, string $to): void
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            $parent = dirname($to);
            if ($parent !== '/' && $parent !== '.') {
                $this->mkdirp($parent);
            }

            if (! $this->sftp->rename($from, $to)) {
                throw new \Exception("Rename failed from {$from} to {$to}");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to rename {$from}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete a remote file
     */
    public function deleteFile(string $remotePath): void
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();
            @$this->sftp->delete($remotePath);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to delete {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete directory recursively
     */
    public function deleteDir(string $remotePath): void
    {
        $this->ensureConnected();

        try {
            // Use exec for recursive deletion - safer than SFTP
            $this->exec('rm -rf '.escapeshellarg($remotePath));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to delete directory {$remotePath}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * List directory contents
     */
    public function listDir(string $path): array
    {
        $this->ensureConnected();

        try {
            $this->initSFTP();

            $raw = $this->sftp->rawlist($path);
            if ($raw === false) {
                return [];
            }

            $entries = [];
            foreach ($raw as $name => $attrs) {
                // Skip . and .. entries
                if ($name === '.' || $name === '..') {
                    continue;
                }

                // Skip symlinks (type 3 in phpseclib3)
                if (($attrs['type'] ?? 0) === 3) {
                    continue;
                }

                $entries[] = [
                    'name' => $name,
                    'type' => ($attrs['type'] ?? 1) === 2 ? 'dir' : 'file',
                    'size' => $attrs['size'] ?? 0,
                    'modified' => $attrs['mtime'] ?? 0,
                ];
            }

            // Sort: directories first, then alphabetically
            usort($entries, function ($a, $b) {
                $typeSort = ($a['type'] === 'dir' ? 0 : 1) - ($b['type'] === 'dir' ? 0 : 1);
                if ($typeSort !== 0) {
                    return $typeSort;
                }

                return strcmp($a['name'], $b['name']);
            });

            return $entries;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to list directory {$path}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Initialize SFTP subsystem
     */
    private function initSFTP(): void
    {
        if ($this->sftp === null) {
            $this->sftp = new SFTP($this->node->ip_address, (int) $this->node->ssh_port);
            $this->sftp->setTimeout($this->timeout);

            $authenticated = false;

            // NOTE: $this->node->ssh_password is already decrypted by the Model's 'encrypted' cast
            // Do NOT call decrypt() on it again
            if ($this->node->ssh_password) {
                $authenticated = @$this->sftp->login(
                    $this->node->ssh_username,
                    $this->node->ssh_password
                );
            }

            if (! $authenticated && $this->node->da_login_key) {
                try {
                    $key = PublicKeyLoader::load($this->node->da_login_key);
                    $authenticated = @$this->sftp->login($this->node->ssh_username, $key);
                } catch (\Exception $e) {
                    throw new \Exception(
                        'SFTP key format invalid: '.$e->getMessage()
                    );
                }
            }

            if (! $authenticated) {
                throw new SSHConnectionException($this->node->ip_address, 'SFTP authentication failed - check credentials');
            }
        }
    }

    /**
     * Disconnect SSH session
     */
    public function disconnect(): void
    {
        if ($this->ssh !== null) {
            @$this->ssh->disconnect();
            $this->connected = false;
        }

        if ($this->sftp !== null) {
            @$this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    /**
     * Destructor - ensure clean disconnect
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
