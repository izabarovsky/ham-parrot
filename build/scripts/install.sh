#!/usr/bin/env bash

echo "Installing Pirrot..."

sudo apt-get update

if [[ -f /etc/os-release ]]; then
    OS=$(grep -w ID /etc/os-release | sed 's/^.*=//')
    VER_NAME=$(grep VERSION /etc/os-release | sed 's/^.*"\(.*\)"/\1/')
    VER_NO=$(grep VERSION_ID /etc/os-release | sed 's/^.*"\(.*\)"/\1/')
 else
    echo "!! INSTALLER ERROR (001) !!"
    echo "The installer could not determine the OS version!"
    echo "Please raise a bug at: https://github.com/allebb/pirrot/issues"
    echo "and ensure you include what version of Raspbian you are trying to"
    echo "install Pirrot on."
    echo ""
fi

echo "OS detected: ${OS} ${VER_NAME}"

if [[ -f /opt/pirrot/build/scripts/os_versions/${OS}_${VER_NO}.install ]]; then
    echo "Running version specific installer steps..."
    source /opt/pirrot/build/scripts/os_versions/${OS}_${VER_NO}.install
 else
    echo "!! INSTALLER ERROR (002) !!"
    echo "The installer could not find Rasbian version specific install sources,"
    echo "Please raise a bug at: https://github.com/allebb/pirrot/issues"
    echo "and ensure you include what version of Raspbian you are trying to"
    echo "install Pirrot on."
    echo ""
fi


echo " # Checking for Pirrot configuration..."
if [[ ! -f /etc/pirrot.conf ]]; then
    echo " - Creating new pirrot.conf from template..."
    sudo cp /opt/pirrot/build/configs/pirrot_default.conf /etc/pirrot.conf
    sudo chmod 0644 /etc/pirrot.conf
fi

echo " # Installing the Pirrot Scheduler..."
sudo cp /opt/pirrot/build/configs/pirrot_scheduler /etc/cron.d/pirrot
sudo chmod 0644 /etc/cron.d/pirrot

echo " # Checking for Pirrot Web Interface configuration..."
if [[ ! -f /opt/pirrot/web/.env ]]; then
    echo " - Setting default configuration for Pirrot Web interface..."
    sudo cp /opt/pirrot/web/.env.example /opt/pirrot/web/.env
fi

echo " # Checking if log files exist..."
if [[ ! -f /var/log/pirrot.log ]]; then
    echo " - Creating log file and setting permissions..."
    sudo touch /var/log/pirrot.log
    sudo chmod 0644 /var/log/pirrot.log
    sudo touch /var/log/pirrot-web.log
    sudo chmod 0644 /var/log/pirrot-web.log
fi

# Chmod it...
echo " - Setting execution bit on /opt/pirrot/pirrot..."
sudo chmod +x /opt/pirrot/pirrot

# Make "pirrot" accessible from the PATH...
sudo ln -s /opt/pirrot/pirrot /usr/local/bin/pirrot

# Chmod storage directories
sudo mkdir /opt/pirrot/storage
sudo mkdir /opt/pirrot/storage/input
sudo mkdir /opt/pirrot/storage/recordings
sudo mkdir /opt/pirrot/storage/backups
sudo mkdir /opt/pirrot/storage/tts
sudo chmod -R 755 /opt/pirrot/storage

# Create new Pirrot Web SQLite database if one doesn't already exist.
if [[ ! -f /opt/pirrot/storage/pirrot-web.database ]]; then
    echo " - Creating empty Pirrot Web database..."
    sudo touch /opt/pirrot/storage/pirrot-web.database
fi

# Symlink our recordings directory to our web interface public directory (so we can list and play them in the browser)
if [[ ! -d /opt/pirrot/web/public/recordings ]]; then
    echo " - Linking the recordings directory to the admin web interface..."
    sudo ln -s /opt/pirrot/storage/recordings/ /opt/pirrot/web/public/recordings
fi

# Copy across the default web admin password (vault) if one doesn't already exist.
if [[ ! -f /opt/pirrot/storage/password.vault ]]; then
    echo " - Setting default password for Pirrot Web interface..."
    sudo cp /opt/pirrot/build/configs/default_password.vault /opt/pirrot/storage/password.vault
fi

# Copy the init.d script...
echo " - Installing the daemon..."
sudo cp /opt/pirrot/build/init.d/pirrot /etc/init.d/pirrot
sudo chmod +x /etc/init.d/pirrot
sudo update-rc.d pirrot defaults

# Installing composer
echo " - Installing Composer..."
wget https://getcomposer.org/composer.phar
sudo mv composer.phar /usr/bin/composer
sudo chmod +x /usr/bin/composer
sudo /usr/bin/composer self-update

# Run composer install...
echo " - Installing Pirrot Dependencies..."
sudo composer install -q --working-dir /opt/pirrot --no-dev --no-interaction
sudo composer install -q --working-dir /opt/pirrot/web --no-dev --no-interaction

# Run database migrations for Pirrot web interface
echo " - Running database updates..."
sudo /usr/bin/php /opt/pirrot/web/artisan migrate --force

# Disable the on-board audio device (to enable USB device)
echo " - Disabling on-board audio device"
sudo sed -i "s|options snd-usb-audio index=-2|#options snd-usb-audio index=-2|" /lib/modprobe.d/aliases.conf
echo "blacklist snd_bcm2835" | sudo tee -a /etc/modprobe.d/raspi-blacklist.conf

# Ask if the user wants to enable the web interface
read -n 1 -p "Do you want to enable the admin web interface? (y/n)? " enableweb
if [ "$enableweb" != "${enableweb#[Yy]}" ] ;then
    sed -i "s|web_interface_enabled = false|web_interface_enabled = true|" /etc/pirrot.conf
    echo ""
    echo " **Web interface has been enabled!**"
    echo ""
    echo "The default credentials are:"
    echo ""
    echo "    URL:      http://{IP_ADDRESS}:8440"
    echo "    Username: admin"
    echo "    Password: pirrot"
    echo ""
    echo "You can reset the password at anytime using the following command:"
    echo ""
    echo " sudo pirrot setwebpwd --password={YourPasswordHere}"
    echo ""
fi

# Finished!
echo ""
echo "Please reboot your RaspberryPi now to enable Pirrot!"
echo ""
while true; do
    read -e -p "Restart your device now (y/n)? " r
    case $r in
    [Yy]* ) break;;
    [Nn]* ) exit;
    esac
done
sudo shutdown -r now
