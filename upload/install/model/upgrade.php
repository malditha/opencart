<?php
class ModelUpgrade extends Model {
	public function mysql() {
		// Upgrade script to opgrade opencart to the latst version. 
		// Oldest version supported is 1.3.2
		
		// Load the sql file
		$file = DIR_APPLICATION . 'opencart.sql';
		
		if (!file_exists($file)) { 
			exit('Could not load sql file: ' . $file); 
		}
		
		$string = '';	
		
		$lines = file($file);
		
		$status = false;	
		
		// Get only the create statements
		foreach($lines as $line) {
			// Set any prefix
			$line = str_replace("CREATE TABLE `oc_", "CREATE TABLE `" . DB_PREFIX, $line);
			
			// If line begins with create table we want to start recording
			if (substr($line, 0, 12) == 'CREATE TABLE') {
				$status = true;	
			}
			
			if ($status) {
				$string .= $line;
			}
			
			// If line contains with ; we want to stop recording
			if (preg_match('/;/', $line)) {
				$status = false;
			}
		}
		
		$table_new_data = array();
				
		// Trim any spaces
		$string = trim($string);
		
		// Trim any ;
		$string = trim($string, ';');
			
		// Start reading each create statement
		$statements = explode(';', $string);
		
		foreach ($statements as $sql) {
			// Get all fields		
			$field_data = array();
			
			preg_match_all('#`(\w[\w\d]*)`\s+((tinyint|smallint|mediumint|bigint|int|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|varchar|char|datetime|date|float|double|decimal|timestamp|time|year|enum|set|binary|varbinary)(\((\d+)(,\s*(\d+))?\))?){1}\s*(collate (\w+)\s*)?(unsigned\s*)?((NOT\s*NULL\s*)|(NULL\s*))?(auto_increment\s*)?(default \'([^\']*)\'\s*)?#i', $sql, $match);

			foreach(array_keys($match[0]) as $key) {
				$field_data[] = array(
					'name'          => trim($match[1][$key]),
					'type'          => strtoupper(trim($match[3][$key])),
					'size'          => str_replace(array('(', ')'), '', trim($match[4][$key])),
					'sizeext'       => trim($match[8][$key]),
					'collation'     => trim($match[9][$key]),
					'unsigned'      => trim($match[10][$key]),
					'notnull'       => trim($match[11][$key]),
					'autoincrement' => trim($match[14][$key]),
					'default'       => trim($match[16][$key]),
				);
			}
						
			// Get primary keys
			$primary_data = array();
			
			preg_match('#primary\s*key\s*\([^)]+\)#i', $sql, $match);
			
			if (isset($match[0])) { 
				preg_match_all('#`(\w[\w\d]*)`#', $match[0], $match); 
			} else{ 
				$match = array();	
			}
			
			if ($match) {
				foreach($match[1] as $primary){
					$primary_data[] = $primary;
				}
			}
			
			// Get indexes
			$index_data = array();
			
			$indexes = array();
			
			preg_match_all('#key\s*`\w[\w\d]*`\s*\(.*\)#i', $sql, $match);

			foreach($match[0] as $key) {
				preg_match_all('#`(\w[\w\d]*)`#', $key, $match);
				
				$indexes[] = $match;
			}
			
			foreach($indexes as $index){
				$key = '';
				
				foreach($index[1] as $field) {
					if ($key == '') {
						$key = $field;
					} else{
						$index_data[$key][] = $field;
					}
				}
			}			
			
			// Table options
			$option_data = array();
			
			preg_match_all('#(\w+)=(\w+)#', $sql, $option);
			
			foreach(array_keys($option[0]) as $key) {
				$option_data[$option[1][$key]] = $option[2][$key];
			}

			// Get Table Name
			preg_match_all('#create\s*table\s*`(\w[\w\d]*)`#i', $sql, $table);
			
			if (isset($table[1][0])) {
				$table_new_data[] = array(
					'sql'     => $sql,
					'name'    => $table[1][0],
					'field'   => $field_data,
					'primary' => $primary_data,
					'index'   => $index_data,
					'option'  => $option_data
				);
			}
		}

		//$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
		$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, 'test');

		// Get all current tables, fields, type, size, etc..
		$table_old_data = array();
		
		$table_query = $db->query("SHOW TABLES FROM `" . 'test' . "`");
				
		foreach ($table_query->rows as $table) {
			if (utf8_substr($table['Tables_in_' . 'test'], 0, strlen(DB_PREFIX)) == DB_PREFIX) {
				$field_data = array(); 
				
				$field_query = $db->query("SHOW COLUMNS FROM `" . $table['Tables_in_' . 'test'] . "`");
				
				foreach ($field_query->rows as $field) {
					preg_match('/\((.*)\)/', $field['Type'], $match);
					
					$field_data[$field['Field']] = array(
						'name'    => $field['Field'],
						'type'    => preg_replace('/\(.*\)/', '', $field['Type']),
						'size'    => isset($match[1]) ? $match[1] : '',
						'null'    => $field['Null'],
						'key'     => $field['Key'],
						'default' => $field['Default'],
						'extra'   => $field['Extra']
					);
				}
				
				$table_old_data[$table['Tables_in_' . 'test']] = $field_data;
			}
		}
						
		foreach ($table_new_data as $table) {
			// If table is not found create it
			if (!isset($table_old_data[$table['name']])) {
				$db->query($table['sql']);
			} else {
				$i = 0;
				
				foreach ($table['field'] as $field) {
					// If field is not found create it
					if (!isset($table_old_data[$table['name']][$field['name']])) {
						$sql = "ALTER TABLE `" . $table['name'] . "` ADD `" . $field['name'] . "` " . $field['type'];
						
						if ($field['size']) {
							$sql .= "(" . $field['size'] . ")";
						}
						
						if ($field['collation']) {
							$sql .= " " . $field['collation'];
						}
						 
						if ($field['notnull']) {
							$sql .= " " . $field['notnull'];
						}
						
						if ($field['default']) {
							$sql .= " DEFAULT '" . $field['default'] . "'";
						}
						
						if ($field['autoincrement']) {
							//$sql .= " AUTO_INCREMENT";
						}
						
						if (isset($table['field'][$i - 1])) {
							$sql .= " AFTER `" . $table['field'][$i - 1]['name'] . "`";
						} else {
							$sql .= " FIRST";
						}
						
						$db->query($sql);
					} else {
						$sql = "ALTER TABLE `" . $table['name'] . "` CHANGE `" . $field['name'] . "` `" . $field['name'] . "`";

						$sql .= " " . strtoupper($field['type']);
													
						if ($field['size']) {
							$sql .= "(" . $field['size'] . ")";
						}
						
						$type_data = array(
							'CHAR',
							'VARCHAR',
							'TINYTEXT',
							'TEXT',
							'MEDIUMTEXT',
							'LONGTEXT',
							'TINYBLOB',
							'BLOB',
							'MEDIUMBLOB',
							'LONGBLOB',
							'ENUM',
							'SET',
							'BINARY',
							'VARBINARY'
						);
											
						if (in_array($field['type'], $type_data)) {
							$sql .= " CHARACTER SET utf8 COLLATE utf8_general_ci";
						}
						
						if ($field['collation']) {
							//$sql .= " " . $field['collation'];
						}
												
						if ($field['notnull']) {
							$sql .= " " . $field['notnull'];
						}
						
						if ($field['default']) {
							$sql .= " DEFAULT '" . $field['default'] . "'";
						}
						
						if ($field['autoincrement']) {
							//$sql .= " AUTO_INCREMENT";
						}
						
						if (isset($table['field'][$i - 1])) {
							$sql .= " AFTER `" . $table['field'][$i - 1]['name'] . "`";
						} else {
							$sql .= " FIRST";
						}
												
						$db->query($sql);
					}
					
					$i++;
				}

				$status = false;
				
				// Drop primary keys and indexes.
				$query = $db->query("SHOW INDEXES FROM `" . $table['name'] . "`");
				
				foreach ($query->rows as $result) {
					if ($result['Key_name'] != 'PRIMARY') {
						$db->query("ALTER TABLE `" . $table['name'] . "` DROP INDEX `" . $result['Key_name'] . "`");
					} else {
						$status = true;
					}
				}
				
				if ($status) {
					$db->query("ALTER TABLE `" . $table['name'] . "` DROP PRIMARY KEY");
				}
				
				// Add a new primary key.
				$primary_data = array();

				foreach ($table['primary'] as $primary) {
					$primary_data[] = "`" . $primary . "`";
				}

				if ($primary_data) {
					$db->query("ALTER TABLE `" . $table['name'] . "` ADD PRIMARY KEY(" . implode(',', $primary_data) . ")");
				}
				
				// Add the new indexes				
				foreach ($table['index'] as $index) {
					$index_data = array();
					
					foreach ($index as $key) {
						$index_data[] = '`' . $key . '`';
					}
					
					if ($index_data) {
						$db->query("ALTER TABLE `" . $table['name'] . "` ADD INDEX (" . implode(',', $index_data) . ")");			
					}	
				}
				
				// Add auto increment to primary keys again 
				foreach ($table['field'] as $field) {
					if ($field['autoincrement']) {
						$sql = "ALTER TABLE `" . $table['name'] . "` CHANGE `" . $field['name'] . "` `" . $field['name'] . "`";
		
						$sql .= " " . strtoupper($field['type']);
								
						if ($field['size']) {
							$sql .= "(" . $field['size'] . ")";
						}
							
						if ($field['collation']) {
							//$sql .= " " . $field['collation'];
						}
						
						$type_data = array(
							'CHAR',
							'VARCHAR',
							'TINYTEXT',
							'TEXT',
							'MEDIUMTEXT',
							'LONGTEXT',
							'TINYBLOB',
							'BLOB',
							'MEDIUMBLOB',
							'LONGBLOB',
							'ENUM',
							'SET',
							'BINARY',
							'VARBINARY'
						);
						
						if (in_array($field['type'], $type_data)) {
							$sql .= " CHARACTER SET utf8 COLLATE utf8_general_ci";
						}
						
						if ($field['notnull']) {
							$sql .= " " . $field['notnull'];
						}
						
						if ($field['default']) {
							$sql .= " DEFAULT '" . $field['default'] . "'";
						}
						
						if ($field['autoincrement']) {
							$sql .= " AUTO_INCREMENT";
						}
												
						$db->query($sql);
					
					}
				}
				
				// Change DB engine
				// ALTER TABLE  `oc_coupon_description` ENGINE = INNODB				
			}
		}
		
		// Settings
		$query = $db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '0' ORDER BY store_id ASC");

		foreach ($query->rows as $setting) {
			if (!$setting['serialized']) {
				$settings[$setting['key']] = $setting['value'];
			} else {
				$settings[$setting['key']] = unserialize($setting['value']);
			}
		}
		
		// Set defaults for new Voucher Min/Max fields if not set
		if (empty($settings['config_voucher_min'])) {
			$db->query("INSERT INTO " . DB_PREFIX . "setting SET value = '1', `key` = 'config_voucher_min', `group` = 'config', store_id = 0");
		}
		
		if (empty($settings['config_voucher_max'])) {
			$db->query("INSERT INTO " . DB_PREFIX . "setting SET value = '1000', `key` = 'config_voucher_max', `group` = 'config', store_id = 0");
		}
		
		if (isset($table_old_data[DB_PREFIX . 'customer_group']['name'])) {
			// Customer Group 'name' field moved to new customer_group_description table. Need to loop through and move over.
			$customer_group_query = $db->query("DESC " . DB_PREFIX . "customer_group `name`");
			
			if ($customer_group_query->num_rows) {
				$customer_group_query = $db->query("SELECT * FROM " . DB_PREFIX . "customer_group");
				
				$default_language_query = $db->query("SELECT language_id FROM " . DB_PREFIX . "language WHERE code = '" . $settings['config_admin_language'] . "'");
				
				$default_language_id = $default_language_query->row['language_id'];
				
				foreach ($customer_group_query->rows as $customer_group) {
					$db->query("INSERT INTO " . DB_PREFIX . "customer_group_description SET customer_group_id = '" . (int)$customer_group['customer_group_id'] . "', language_id = '" . (int)$default_language_id . "', `name` = '" . $db->escape($customer_group['name']) . "' ON DUPLICATE KEY UPDATE customer_group_id = customer_group_id");
				}
				
				// Comment this for now in case people want to roll back to 1.5.2 from 1.5.3
				// Uncomment it when 1.5.4 is out.
				$db->query("ALTER TABLE `" . DB_PREFIX . "customer_group` DROP `name`");			
			}
		}
		
		// We can do all the SQL changes here
				
		// Sort the categories to take advantage of the nested set model
		$this->repairCategories(0);
	}
	
	// Function to repair any erroneous categories that are not in the category path table.
	public function repairCategories($parent_id = 0) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category WHERE parent_id = '" . (int)$parent_id . "'");
		
		foreach ($query->rows as $category) {
			// Delete the path below the current one
			$this->db->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . (int)$category['category_id'] . "'");
			
			// Fix for records with no paths
			$level = 0;
			
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . (int)$parent_id . "' ORDER BY level ASC");
			
			foreach ($query->rows as $result) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET category_id = '" . (int)$category['category_id'] . "', `path_id` = '" . (int)$result['path_id'] . "', level = '" . (int)$level . "'");
				
				$level++;
			}
			
			$this->db->query("REPLACE INTO `" . DB_PREFIX . "category_path` SET category_id = '" . (int)$category['category_id'] . "', `path_id` = '" . (int)$category['category_id'] . "', level = '" . (int)$level . "'");
						
			$this->repairCategories($category['category_id']);
		}
	}
}
?>