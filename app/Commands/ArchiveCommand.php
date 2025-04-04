<?php

namespace Ballen\Pirrot\Commands;

use Ballen\Clip\Traits\RecievesArgumentsTrait;
use Ballen\Clip\Interfaces\CommandInterface;
use Ballen\Clip\Utilities\ArgumentsParser;
use Ballen\Collection\Collection;

/**
 * Class ArchiveCommand
 *
 * @package Ballen\Pirrot\Commands
 */
class ArchiveCommand extends BaseCommand implements CommandInterface
{

    use RecievesArgumentsTrait;

    /**
     * IdentCommand constructor.
     * @param ArgumentsParser $argv
     */
    public function __construct(ArgumentsParser $argv)
    {
        parent::__construct($argv);
    }

    /**
     * Handle the command.
     * @return void
     */
    public function handle()
    {
        if (!$this->config->get('archive_enabled', false)) {
            $this->writeln($this->getCurrentLogTimestamp() . 'The archive recording setting is not enabled, exiting!');
            $this->exitWithSuccess();
        }

        $recordings_storage_path = $this->basePath . '/storage/recordings/';

        // Get a list of recording to upload...
        $filesToArchive = new Collection();
        $recordings_in_directory = array_diff(scandir($recordings_storage_path), array('.', '..'));

        // Create a new File object from each file and add to our file collection.
        foreach ($recordings_in_directory as $file) {
            $filesToArchive->push(new \SplFileInfo($recordings_storage_path . $file));
        }

        if (count($recordings_in_directory) < 1) {
            $this->writeln('No recordings found, exiting!');
        }

        // Resolve FTP server details from the Pirrot configuration file.
        $ftpHost = $this->config->get('ftp_host');
        $ftpPort = $this->config->get('ftp_port', 21);
        $ftpPassive = $this->config->get('ftp_passive', false);
        $ftpSsl = $this->config->get('ftp_ssl', false);
        $ftpTimeout = $this->config->get('ftp_timeout', 30);
        $ftpUser = $this->config->get('ftp_user');
        $ftpPass = $this->config->get('ftp_pass');
        $ftpPath = $this->config->get('ftp_path');
        $delete_local = $this->config->get('ftp_delete_on_success');

        if ($ftpSsl) {
            $connection = ftp_ssl_connect($ftpHost, $ftpPort, $ftpTimeout);
        } else {
            $connection = ftp_connect($ftpHost, $ftpPort, $ftpTimeout);
        }

        if($ftpPassive){
            ftp_pasv($connection, true);
        }

        if (!$connection) {
            $this->writeln($this->getCurrentLogTimestamp() . 'Unable to connect to the FTP(S) (recording archive) server at: ' . $ftpHost . ':' . $ftpPort);
            $this->exitWithError();
        }

        if (!$session = ftp_login($connection, $ftpUser, $ftpPass)) {
            $this->writeln($this->getCurrentLogTimestamp() . 'Invalid user credentials provided, check username and password and try again!');
            $this->exitWithError();
        }

        $total_uploaded = 0;

        // Attempt to upload (and delete locally, if set) each of the audio recordings found on disk.
        foreach ($filesToArchive->all()->toArray() as $file) {
            if (!ftp_put($connection, $ftpPath . $file->getBasename(), $file->getRealPath(), FTP_BINARY)) {
                $this->writeln($this->getCurrentLogTimestamp() . 'An error occurred attempting to upload the file: ' . $file->getRealPath());
                continue; // Prevent the failed file from being deleted (if local file deletion is enabled).
            }
            if ($delete_local) {
                unlink($file->getRealPath());
            }
            $total_uploaded++;
        }

        ftp_close($connection);
        $this->writeln($this->getCurrentLogTimestamp() . 'Remote archive task uploaded ' . $total_uploaded . ' files.');

    }


}