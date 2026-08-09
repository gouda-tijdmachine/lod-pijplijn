#!/bin/bash

CONFIG_FILE="../../../omeka-s-config/database.ini"

# Check if the file exists
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Configuration file '$CONFIG_FILE' not found."
    exit 1
fi

# Function to get a value for a given key
get_config_value() {
    local key=$1
    # Use grep to find the line, remove comments and whitespace, then awk to extract the value
    grep "^$key" "$CONFIG_FILE" | grep -v ';' | awk -F'=' '{print $2}' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/"//g'
}

DB_user=$(get_config_value "user")
DB_password=$(get_config_value "password")
DB_dbname=$(get_config_value "dbname")
DB_host=$(get_config_value "host")

DATEMODIFIED=`date +%Y-%m-%d`

mysql -h $DB_host -u $DB_user -p$DB_password omeka -e "UPDATE omeka.value SET value='$DATEMODIFIED' WHERE id=325719; UPDATE omeka.resource SET modified=NOW() WHERE id=34508;"