<?php

namespace App\Services\SSH;

use App\Exceptions\SSH\SSHCommandException;
use App\Exceptions\SSH\SSHConnectionException;
use App\Models\Node;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

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
            if ($this->node->ssh_password) {
                $authenticated = @$this->ssh->login(
                    $this->node->ssh_username,
                    decrypt($this->node->ssh_password)
                );
            }

            // Fallback to login key if available and password failed
            if (! $authenticated && $this->node->da_login_key) {
                try {
                    $key = PublicKeyLoader::load(decrypt($this->node->da_login_key));
                    $authenticated = @$this->ssh->login($this->node->ssh_username, $key);
                } catch (\Exception $e) {
                    // Key loading failed, continue to error
                }
            }

            if (! $authenticated) {
                throw new \Exception('Authentication failed');
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
                    "Command exited with status " . $this->ssh->getExitStatus()
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
                "Failed to upload {$remotePath}: " . $e->getMessage(),
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
                throw new \Exception("File not found or read failed");
            }

            return $content;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to download {$remotePath}: " . $e->getMessage(),
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
                "Failed to delete {$remotePath}: " . $e->getMessage(),
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
            $this->exec("rm -rf " . escapeshellarg($remotePath));
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to delete directory {$remotePath}: " . $e->getMessage(),
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

            if ($this->node->ssh_password) {
                $authenticated = @$this->sftp->login(
                    $this->node->ssh_username,
                    decrypt($this->node->ssh_password)
                );
            }

            if (! $authenticated && $this->node->da_login_key) {
                try {
                    $key = PublicKeyLoader::load(decrypt($this->node->da_login_key));
                    $authenticated = @$this->sftp->login($this->node->ssh_username, $key);
                } catch (\Exception $e) {
                    // Key loading failed
                }
            }

            if (! $authenticated) {
                throw new SSHConnectionException($this->node->ip_address, 'SFTP authentication failed');
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
