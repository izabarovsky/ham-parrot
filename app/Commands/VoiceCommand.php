<?php

namespace Ballen\Pirrot\Commands;

use Ballen\Clip\Traits\RecievesArgumentsTrait;
use Ballen\Clip\Interfaces\CommandInterface;
use Ballen\Clip\Utilities\ArgumentsParser;
use Ballen\GPIO\GPIO;
use Ballen\GPIO\Exceptions\GPIOException;

/**
 * Class VoiceCommand
 *
 * @package Ballen\Pirrot\Commands
 */
class VoiceCommand extends AudioCommand implements CommandInterface
{

    use RecievesArgumentsTrait;

    /**
     * The TX/RX mode for voice communications.
     *
     * @var string
     */
    private $mode;

    /**
     * Stores value of the COR recording state.
     *
     * @var bool
     */
    private $corRecording = false;

    /**
     * How long to wait before value on COR pin settles - in seconds.
     *
     * @var int
     */
    private $debounceTime = 0.25;

    /**
     * Provides a transmitter timeout protection state.
     * @var bool
     */
    private $timeoutReset = true;

    /**
     * VoiceCommand constructor.
     * @param ArgumentsParser $argv
     * @throws GPIOException
     */
    public function __construct(ArgumentsParser $argv)
    {
        parent::__construct($argv);

        // Sets the transmit/receive mode
        $this->mode = ucwords($this->config->get('transmit_mode'));

        // Sets the default LED's and outputs
        $this->outputLedPwr->setValue(GPIO::HIGH);
        $this->outputPtt->setValue(GPIO::LOW);
        $this->outputLedRx->setValue(GPIO::LOW);
        $this->outputLedTx->setValue(GPIO::LOW);
    }

    /**
     * Handle the command.
     * @return void
     * @throws GPIOException
     */
    public function handle()
    {

        $this->writeln($this->getCurrentLogTimestamp() . 'Pirrot is starting...');

        // Detect if the repeater is enabled/disabled...
        if (!$this->config->get('enabled', false)) {
            $this->writeln('Repeater disabled in the configuration file, will run in "listen only" mode');
        }

        $this->setProcessName('pirrot-repeater');

        $this->setPowerLed();

        // Format method name from the setting value
        $initMethod = str_replace(' ', '', ucwords(str_replace('-', ' ', $this->mode)));

        // Detect and handle the current RX/TX mode...
        $modeHandler = "main{$initMethod}";
        if (method_exists($this, $modeHandler)) {
            return $this->{$modeHandler}();
        }
        $this->writeln("RX/TX mode ({$this->mode}) not supported!");
        $this->exitWithError();
    }

    /**
     * Main loop handler for VOX transmission operations.
     *
     * @throws GPIOException
     * @retun void
     */
    private function mainSimplexVox()
    {

        while (true) {
            system($this->audioService->audioRecordBin . ' -t ' . trim($this->config->get('record_device',
                    'alsa')) . ' default ' . $this->basePath . '/storage/input/buffer.ogg -V0 silence ' . trim($this->config->get('vox_tuning')));
            $this->storeRecording();

            if (!$this->config->get('enabled', false)) {
                return; // If the repeater is not enabled, return early so we don't transmit...
            }

            $this->outputLedTx->setValue(GPIO::HIGH);
            $this->processDelayTransmissionSettings();
            $this->outputPtt->setValue(GPIO::HIGH);
            $this->audioService->play($this->basePath . '/storage/input/buffer.ogg');
            $this->sendCourtesyTone();
            $this->outputLedTx->setValue(GPIO::LOW);
            $this->outputPtt->setValue(GPIO::LOW);
            $this->dispatchTripwire();
        }
    }

    /**
     * Main loop handler for COR transmission operations.
     *
     * @throws GPIOException
     * @retun void
     */
    private function mainSimplexCor()
    {
        while (true) {
            $this->processSimplexCorRecording();
        }
    }

    /**
     * Main loop handler for COR duplex mode operations.
     *
     * @throws GPIOException
     * @retun void
     */
    private function mainDuplexCor()
    {
        while (true) {
            $this->processDuplexCorRecording();
        }
    }

    /**
     * If enabled, archives/stores the audio transmission.
     *
     * @return void
     */
    private function storeRecording()
    {
        if ($this->config->get('store_recordings')) {
            $date = date('YmdHis');
            copy($this->basePath . '/storage/input/buffer.ogg',
                $this->basePath . '/storage/recordings/' . $date . '.ogg');
        }
    }

    /**
     * If enabled, plays the courtesy tone at the end of transmissions.
     *
     * @return void
     */
    private function sendCourtesyTone()
    {
        if ($this->config->get('courtesy_tone', false)) {
            $this->audioService->tone($this->config->get('courtesy_tone', 'Beep'));
        }
    }

    /**
     * Processes any optional delayed playback interval in the configuration file (default this is disabled).
     * @return void
     */
    private function processDelayTransmissionSettings()
    {
        usleep($this->config->get('delayed_playback_interval') * 1000000);
    }

    /**
     * Handles the COR recording logic
     * @return void
     * @throws GPIOException
     */
    private function processSimplexCorRecording()
    {
        
        // If the transmit timeout has been reached, lets monitor until we can reset it.
        if (!$this->timeoutReset) {
            usleep(10000);
            if ($this->inputCos->getValue() == GPIO::HIGH) {
                return;
            }
            $this->timeoutReset = true;
        }

        // Normal operation, process Simplex transmission...
        if (!$this->corRecording && ($this->inputCos->getValue() == GPIO::HIGH)) {

            $transmit_timeout = time() + $this->config->get('transmit_timeout', 120);

            $this->outputLedRx->setValue(GPIO::HIGH);
            $pid = system($this->audioService->audioRecordBin . ' -t ' . trim($this->config->get('record_device',
                    'alsa')) . ' default ' . $this->basePath . '/storage/input/buffer.ogg > /dev/null & echo $!');
            $this->corRecording = true;
            $timeout = microtime(true) + $this->debounceTime;

            while (true) {

                if ($this->inputCos->getValue() == GPIO::HIGH) {
                    $timeout = microtime(true) + $this->debounceTime;
                }

                usleep(10000);
                if ($tor = (time() > $transmit_timeout) || ($timeout < microtime(true))) {

                    if ($tor) { // Timeout reached..
                        $this->writeln($this->getCurrentLogTimestamp() . 'Timeout reached');
                        $this->timeoutReset = false;
                    }

                    $this->outputLedRx->setValue(GPIO::LOW);

                    //sleep(1); // Ensures that the EoT is not cut from the recording and gives SOX time to write to the disk before the kernel kills the process.
                    system('kill -2 ' . $pid);

                    $this->storeRecording();

                    if (!$this->config->get('enabled', false)) {
                        $this->corRecording = false;
                        break;  // If the repeater is not enabled, break out early so we don't transmit...
                    }

                    $this->outputLedTx->setValue(GPIO::HIGH);
                    $this->processDelayTransmissionSettings();
                    $this->outputPtt->setValue(GPIO::HIGH);
                    $this->audioService->play($this->basePath . '/storage/input/buffer.ogg');
                    $this->sendCourtesyTone();
                    $this->outputPtt->setValue(GPIO::LOW);
                    $this->outputLedTx->setValue(GPIO::LOW);
                    $this->corRecording = false;
                    $this->dispatchTripwire();
                    break;
                }
            }
        }

        usleep(10000); // Sleep a tenth of a second...
    }

    /**
     * Handles the Duplex COR logic
     * @return void
     * @throws GPIOException
     */
    private function processDuplexCorRecording()
    {

        // If the transmit timeout has been reached, lets monitor until we can reset it.
        if (!$this->timeoutReset) {
            usleep(10000);
            if ($this->inputCos->getValue() == GPIO::HIGH) {
                return;
            }
            $this->timeoutReset = true;
        }

        // Normal operation, process Duplex transmission...
        if (!$this->corRecording && ($this->inputCos->getValue() == GPIO::HIGH)) {
            $transmit_timeout = time() + $this->config->get('transmit_timeout', 120);

            $this->outputLedRx->setValue(GPIO::HIGH);
            $this->outputPtt->setValue(GPIO::HIGH);
            $this->outputLedTx->setValue(GPIO::HIGH);

            // Record (if the system option is set to do so)
            $pid = system($this->audioService->audioRecordBin . ' -t ' . trim($this->config->get('record_device',
                    'alsa')) . ' default ' . $this->basePath . '/storage/input/buffer.ogg > /dev/null & echo $!');
            $this->corRecording = true;
            $timeout = microtime(true) + $this->debounceTime;

            while (true) {

                if ($this->inputCos->getValue() == GPIO::HIGH) {
                    $timeout = microtime(true) + $this->debounceTime;
                }

                usleep(10000);
                if ($tor = (time() > $transmit_timeout) || ($timeout < microtime(true))) {

                    if ($tor) { // Timeout reached..
                        $this->writeln($this->getCurrentLogTimestamp() . 'Timeout reached');
                        $this->timeoutReset = false;
                    }

                    $this->outputLedRx->setValue(GPIO::LOW);

                    //sleep(1); // Ensures that the EoT is not cut from the recording and gives SOX time to write to the disk before the kernel kills the process.
                    system('kill -2 ' . $pid);

                    $this->sendCourtesyTone();
                    $this->outputPtt->setValue(GPIO::LOW);
                    $this->outputLedTx->setValue(GPIO::LOW);

                    $this->storeRecording();

                    $this->corRecording = false;

                    $this->dispatchTripwire();
                    break;
                }
            }
        }

        usleep(10000); // Sleep a tenth of a second...
    }

    /**
     * Dispatches a new "Tripwire" request (HTTP Webhook).
     * @return void
     */
    private function dispatchTripwire(): void
    {
        if ($this->config->get('tripwire_enabled', false)) {
            shell_exec('/opt/pirrot/pirrot tripwire --url="' . $this->config->get('tripwire_url',
                    null) . '" 2> /dev/null &');
        }
    }
}