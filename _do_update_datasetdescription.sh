#!/bin/bash

CONFIG_FILE="../../../omeka-s-config/database.ini"

# Check if the file exists
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Configuration file '$CONFIG_FILE' not found."
    exit 1
fi


NTRIPLES="out/goudatijdmachine.nt.gz"

if [ ! -f "$NTRIPLES" ]; then
	echo "Error: '$NTRIPLES' not found."
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

echo "4.1 - Updating distribution size in database"

# Change distribution properties
# contentSize omeka.value[id=325720]=xxx

DATEMODIFIED=`date +%Y-%m-%d`
CONTENTSIZE=$(stat -c%s "$NTRIPLES")

mysql -h $DB_host -u $DB_user -p$DB_password omeka -e "UPDATE omeka.value SET value='$CONTENTSIZE' WHERE id=325720; UPDATE omeka.value SET value='$DATEMODIFIED' WHERE id=325719; UPDATE omeka.resource SET modified=now() WHERE id=34508;"

# de contentSize van de distributie is per definitie oud omdat waarde wordt aangepast na dat N-triples zijn verzameld, zucht
# de modifiedDate wijziging verhuist naar update_modified_data_resource.sh

echo "4.2 - Updating GTM datasetdescriptions"

/home/http/goudatijdmachine.nl/bin/datasetdescriptions/datacatalog.sh

#echo "4.3 - Registering https://www.goudatijdmachine.nl/.well-known/datacatalog with the datasetregister to update"
#
#curl 'https://datasetregister.netwerkdigitaalerfgoed.nl/api/datasets' \
#	-H 'link: <http://www.w3.org/ns/ldp#RDFSource>; rel="type",<http://www.w3.org/ns/ldp#Resource>; rel="type"' \
#	-H 'content-type: application/ld+json' \
#	--data-binary '{"@id":"https://www.goudatijdmachine.nl/.well-known/datacatalog"}'