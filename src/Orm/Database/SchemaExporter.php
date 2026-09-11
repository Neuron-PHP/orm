<?php

namespace Neuron\Orm\Database;

use Neuron\Core\System\IFileSystem;
use Neuron\Core\System\RealFileSystem;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\AdapterFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports database schema to YAML format for reference purposes
 * Similar to Rails' schema.rb functionality
 */
class SchemaExporter
{
	private AdapterInterface $_Adapter;
	private string $_MigrationTable;
	private string $_AdapterType;
	private IFileSystem $fs;

	/**
	 * @param Config $PhinxConfig Phinx configuration
	 * @param string $Environment Environment name
	 * @param string $MigrationTable Migration tracking table name
	 * @param IFileSystem|null $fs File system implementation (null = use real file system)
	 */
	public function __construct( Config $PhinxConfig, string $Environment, string $MigrationTable = 'phinx_log', ?IFileSystem $fs = null )
	{
		$this->_MigrationTable = $MigrationTable;
		$this->fs = $fs ?? new RealFileSystem();

		// Create database adapter from Phinx config
		$options = $PhinxConfig->getEnvironment( $Environment );
		$this->_Adapter = AdapterFactory::instance()->getAdapter(
			$options['adapter'],
			$options
		);

		// Connect to database
		$this->_Adapter->connect();

		// Store adapter type
		$this->_AdapterType = $this->_Adapter->getAdapterType();
	}

	/**
	 * Export schema to YAML string
	 *
	 * @return string YAML representation of schema
	 */
	public function export(): string
	{
		$schema = $this->buildSchemaArray();

		return Yaml::dump( $schema, 4, 2 );
	}

	/**
	 * Export schema to file
	 *
	 * @param string $FilePath Path to output file
	 * @return bool Success status
	 */
	public function exportToFile( string $FilePath ): bool
	{
		$yaml = $this->export();

		$directory = dirname( $FilePath );
		if( !$this->fs->isDir( $directory ) )
		{
			$this->fs->mkdir( $directory, 0755, true );
		}

		$result = $this->fs->writeFile( $FilePath, $yaml );

		return $result !== false;
	}

	/**
	 * Build schema array structure
	 *
	 * @return array
	 */
	private function buildSchemaArray(): array
	{
		$schema = [
			'version' => $this->getLatestMigrationVersion(),
			'tables' => []
		];

		$tables = $this->getTables();

		foreach( $tables as $tableName )
		{
			// Skip migration tracking table
			if( $tableName === $this->_MigrationTable )
			{
				continue;
			}

			$schema['tables'][$tableName] = $this->getTableSchema( $tableName );
		}

		return $schema;
	}

	/**
	 * Get latest migration version from tracking table
	 *
	 * @return string|null
	 */
	private function getLatestMigrationVersion(): ?string
	{
		if( !$this->_Adapter->hasTable( $this->_MigrationTable ) )
		{
			return null;
		}

		$result = $this->_Adapter->fetchRow(
			"SELECT version FROM {$this->_MigrationTable} ORDER BY version DESC LIMIT 1"
		);

		return $result ? (string)$result['version'] : null;
	}

	/**
	 * Get list of all tables in database
	 *
	 * @return array
	 */
	private function getTables(): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				// Use PDO for parameter binding - Phinx adapters don't support it
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					try
					{
						$connection = $this->_Adapter->getConnection();
						if( $connection instanceof \PDO )
						{
							$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
									WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
									ORDER BY TABLE_NAME";
							$stmt = $connection->prepare( $sql );
							if( $stmt !== false )
							{
								$stmt->execute( [$this->_Adapter->getOption( 'name' )] );
								$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
								return array_column( $rows, 'TABLE_NAME' );
							}
						}
					}
					catch( \Exception $e )
					{
						// Fall through to fallback
					}
				}

				// Fallback: Use quote method if available, or simple escaping
				$dbName = $this->_Adapter->getOption( 'name' );
				if( method_exists( $this->_Adapter, 'getConnection' ) )
				{
					try
					{
						$connection = $this->_Adapter->getConnection();
						if( $connection instanceof \PDO )
						{
							// Use PDO quote (returns quoted string with surrounding quotes)
							$quotedDbName = $connection->quote( $dbName );
							$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
									WHERE TABLE_SCHEMA = {$quotedDbName} AND TABLE_TYPE = 'BASE TABLE'
									ORDER BY TABLE_NAME";
							$rows = $this->_Adapter->fetchAll( $sql );
							return array_column( $rows, 'TABLE_NAME' );
						}
					}
					catch( \Exception $e )
					{
						// Fall through to basic escaping
					}
				}

				// Final fallback: Basic escaping (less secure)
				$dbName = str_replace( ["'", "\\"], ["''", "\\\\"], $dbName ?? '' );
				$sql = "SELECT TABLE_NAME FROM information_schema.TABLES
						WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_TYPE = 'BASE TABLE'
						ORDER BY TABLE_NAME";
				$rows = $this->_Adapter->fetchAll( $sql );
				return array_column( $rows, 'TABLE_NAME' );

			case 'pgsql':
				$sql = "SELECT tablename FROM pg_catalog.pg_tables
						WHERE schemaname = 'public'
						ORDER BY tablename";
				$rows = $this->_Adapter->fetchAll( $sql );
				return array_column( $rows, 'tablename' );

			case 'sqlite':
				$sql = "SELECT name FROM sqlite_master
						WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
						ORDER BY name";
				$rows = $this->_Adapter->fetchAll( $sql );
				return array_column( $rows, 'name' );

			default:
				throw new \RuntimeException( "Unsupported adapter type: {$this->_AdapterType}" );
		}
	}

	/**
	 * Get schema for a specific table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getTableSchema( string $tableName ): array
	{
		$tableSchema = [
			'columns' => $this->getColumns( $tableName )
		];

		$indexes = $this->getIndexes( $tableName );
		if( !empty( $indexes ) )
		{
			$tableSchema['indexes'] = $indexes;
		}

		$foreignKeys = $this->getForeignKeys( $tableName );
		if( !empty( $foreignKeys ) )
		{
			$tableSchema['foreign_keys'] = $foreignKeys;
		}

		return $tableSchema;
	}

	/**
	 * Get columns for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getColumns( string $tableName ): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				return $this->getColumnsMysql( $tableName );
			case 'pgsql':
				return $this->getColumnsPostgres( $tableName );
			case 'sqlite':
				return $this->getColumnsSqlite( $tableName );
			default:
				throw new \RuntimeException( "Unsupported adapter type: {$this->_AdapterType}" );
		}
	}

	/**
	 * Get columns for MySQL
	 */
	private function getColumnsMysql( string $tableName ): array
	{
		$sql = "SELECT
					COLUMN_NAME,
					DATA_TYPE,
					COLUMN_TYPE,
					IS_NULLABLE,
					COLUMN_DEFAULT,
					COLUMN_KEY,
					EXTRA,
					CHARACTER_MAXIMUM_LENGTH,
					NUMERIC_PRECISION,
					NUMERIC_SCALE,
					COLUMN_COMMENT
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
				ORDER BY ORDINAL_POSITION";

		// Phinx adapters don't support parameterized queries natively
		// Try to use PDO prepared statements directly
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [
							$this->_Adapter->getOption( 'name' ),
							$tableName
						] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback method
			}
		}

		// Fallback: Use escaping for parameters
		if( empty( $rows ) )
		{
			$dbName = $this->_Adapter->getOption( 'name' ) ?? '';
			$dbName = str_replace( ["'", "\\"], ["''", "\\\\"], $dbName );
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );

			$sql = "SELECT
						COLUMN_NAME,
						DATA_TYPE,
						COLUMN_TYPE,
						IS_NULLABLE,
						COLUMN_DEFAULT,
						COLUMN_KEY,
						EXTRA,
						CHARACTER_MAXIMUM_LENGTH,
						NUMERIC_PRECISION,
						NUMERIC_SCALE,
						COLUMN_COMMENT
					FROM information_schema.COLUMNS
					WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$tableName}'
					ORDER BY ORDINAL_POSITION";

			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$columns = [];
		foreach( $rows as $row )
		{
			$columnInfo = [
				'type' => $this->normalizeType( $row['DATA_TYPE'] ),
				'null' => $row['IS_NULLABLE'] === 'YES'
			];

			if( $row['CHARACTER_MAXIMUM_LENGTH'] )
			{
				$columnInfo['limit'] = (int)$row['CHARACTER_MAXIMUM_LENGTH'];
			}

			if( $row['NUMERIC_PRECISION'] )
			{
				$columnInfo['precision'] = (int)$row['NUMERIC_PRECISION'];
			}

			if( $row['NUMERIC_SCALE'] )
			{
				$columnInfo['scale'] = (int)$row['NUMERIC_SCALE'];
			}

			if( $row['COLUMN_KEY'] === 'PRI' )
			{
				$columnInfo['primary'] = true;
			}

			if( str_contains( $row['EXTRA'], 'auto_increment' ) )
			{
				$columnInfo['auto_increment'] = true;
			}

			if( $row['COLUMN_DEFAULT'] !== null )
			{
				$columnInfo['default'] = $row['COLUMN_DEFAULT'];
			}

			if( !empty( $row['COLUMN_COMMENT'] ) )
			{
				$columnInfo['comment'] = $row['COLUMN_COMMENT'];
			}

			$columns[$row['COLUMN_NAME']] = $columnInfo;
		}

		return $columns;
	}

	/**
	 * Get columns for PostgreSQL
	 */
	private function getColumnsPostgres( string $tableName ): array
	{
		$sql = "SELECT
					c.column_name,
					c.data_type,
					c.is_nullable,
					c.column_default,
					c.character_maximum_length,
					c.numeric_precision,
					c.numeric_scale,
					pg_catalog.col_description(
						(SELECT oid FROM pg_catalog.pg_class WHERE relname = c.table_name),
						c.ordinal_position
					) as column_comment
				FROM information_schema.columns c
				WHERE c.table_schema = 'public' AND c.table_name = ?
				ORDER BY c.ordinal_position";

		// Phinx adapters don't support parameterized queries natively
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [$tableName] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback
			}
		}

		// Fallback: Use escaping
		if( empty( $rows ) )
		{
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );
			$sql = "SELECT
						c.column_name,
						c.data_type,
						c.is_nullable,
						c.column_default,
						c.character_maximum_length,
						c.numeric_precision,
						c.numeric_scale,
						pg_catalog.col_description(
							(SELECT oid FROM pg_catalog.pg_class WHERE relname = c.table_name),
							c.ordinal_position
						) as column_comment
					FROM information_schema.columns c
					WHERE c.table_schema = 'public' AND c.table_name = '{$tableName}'
					ORDER BY c.ordinal_position";
			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$columns = [];
		foreach( $rows as $row )
		{
			$columnInfo = [
				'type' => $this->normalizeType( $row['data_type'] ),
				'null' => $row['is_nullable'] === 'YES'
			];

			if( $row['character_maximum_length'] )
			{
				$columnInfo['limit'] = (int)$row['character_maximum_length'];
			}

			if( $row['numeric_precision'] )
			{
				$columnInfo['precision'] = (int)$row['numeric_precision'];
			}

			if( $row['numeric_scale'] )
			{
				$columnInfo['scale'] = (int)$row['numeric_scale'];
			}

			if( $row['column_default'] !== null )
			{
				$columnInfo['default'] = $row['column_default'];
			}

			if( !empty( $row['column_comment'] ) )
			{
				$columnInfo['comment'] = $row['column_comment'];
			}

			$columns[$row['column_name']] = $columnInfo;
		}

		return $columns;
	}

	/**
	 * Get columns for SQLite
	 */
	private function getColumnsSqlite( string $tableName ): array
	{
		$sql = "PRAGMA table_info({$tableName})";
		$rows = $this->_Adapter->fetchAll( $sql );

		$columns = [];
		foreach( $rows as $row )
		{
			$columnInfo = [
				'type' => $this->normalizeType( $row['type'] ),
				'null' => $row['notnull'] == 0
			];

			if( $row['pk'] == 1 )
			{
				$columnInfo['primary'] = true;
				$columnInfo['auto_increment'] = true;
			}

			if( $row['dflt_value'] !== null )
			{
				$columnInfo['default'] = $row['dflt_value'];
			}

			$columns[$row['name']] = $columnInfo;
		}

		return $columns;
	}

	/**
	 * Get indexes for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getIndexes( string $tableName ): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				return $this->getIndexesMysql( $tableName );
			case 'pgsql':
				return $this->getIndexesPostgres( $tableName );
			case 'sqlite':
				return $this->getIndexesSqlite( $tableName );
			default:
				throw new \RuntimeException( "Unsupported adapter type: {$this->_AdapterType}" );
		}
	}

	/**
	 * Get indexes for MySQL
	 */
	private function getIndexesMysql( string $tableName ): array
	{
		$sql = "SELECT
					INDEX_NAME,
					COLUMN_NAME,
					NON_UNIQUE
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
				ORDER BY INDEX_NAME, SEQ_IN_INDEX";

		// Phinx adapters don't support parameterized queries natively
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [
							$this->_Adapter->getOption( 'name' ),
							$tableName
						] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback
			}
		}

		// Fallback: Use escaping
		if( empty( $rows ) )
		{
			$dbName = $this->_Adapter->getOption( 'name' ) ?? '';
			$dbName = str_replace( ["'", "\\"], ["''", "\\\\"], $dbName );
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );

			$sql = "SELECT
						INDEX_NAME,
						COLUMN_NAME,
						NON_UNIQUE
					FROM information_schema.STATISTICS
					WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$tableName}'
					ORDER BY INDEX_NAME, SEQ_IN_INDEX";
			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$indexes = [];
		$indexGroups = [];

		foreach( $rows as $row )
		{
			// Skip primary key
			if( $row['INDEX_NAME'] === 'PRIMARY' )
			{
				continue;
			}

			if( !isset( $indexGroups[$row['INDEX_NAME']] ) )
			{
				$indexGroups[$row['INDEX_NAME']] = [
					'name' => $row['INDEX_NAME'],
					'columns' => [],
					'unique' => $row['NON_UNIQUE'] == 0
				];
			}

			$indexGroups[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
		}

		foreach( $indexGroups as $index )
		{
			$indexInfo = [
				'name' => $index['name'],
				'columns' => $index['columns']
			];

			if( $index['unique'] )
			{
				$indexInfo['unique'] = true;
			}

			$indexes[] = $indexInfo;
		}

		return $indexes;
	}

	/**
	 * Get indexes for PostgreSQL
	 */
	private function getIndexesPostgres( string $tableName ): array
	{
		$sql = "SELECT
					i.relname as index_name,
					a.attname as column_name,
					ix.indisunique as is_unique
				FROM pg_class t
				JOIN pg_index ix ON t.oid = ix.indrelid
				JOIN pg_class i ON i.oid = ix.indexrelid
				JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
				WHERE t.relname = ? AND t.relkind = 'r'
				ORDER BY i.relname, a.attnum";

		// Phinx adapters don't support parameterized queries natively
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [$tableName] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback
			}
		}

		// Fallback: Use escaping
		if( empty( $rows ) )
		{
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );
			$sql = "SELECT
						i.relname as index_name,
						a.attname as column_name,
						ix.indisunique as is_unique
					FROM pg_class t
					JOIN pg_index ix ON t.oid = ix.indrelid
					JOIN pg_class i ON i.oid = ix.indexrelid
					JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
					WHERE t.relname = '{$tableName}' AND t.relkind = 'r'
					ORDER BY i.relname, a.attnum";
			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$indexes = [];
		$indexGroups = [];

		foreach( $rows as $row )
		{
			// Skip primary key
			if( str_contains( $row['index_name'], '_pkey' ) )
			{
				continue;
			}

			if( !isset( $indexGroups[$row['index_name']] ) )
			{
				$indexGroups[$row['index_name']] = [
					'name' => $row['index_name'],
					'columns' => [],
					'unique' => $row['is_unique'] === 't'
				];
			}

			$indexGroups[$row['index_name']]['columns'][] = $row['column_name'];
		}

		foreach( $indexGroups as $index )
		{
			$indexInfo = [
				'name' => $index['name'],
				'columns' => $index['columns']
			];

			if( $index['unique'] )
			{
				$indexInfo['unique'] = true;
			}

			$indexes[] = $indexInfo;
		}

		return $indexes;
	}

	/**
	 * Get indexes for SQLite
	 */
	private function getIndexesSqlite( string $tableName ): array
	{
		$sql = "PRAGMA index_list({$tableName})";
		$indexList = $this->_Adapter->fetchAll( $sql );

		$indexes = [];

		foreach( $indexList as $index )
		{
			// Skip auto-indexes
			if( str_starts_with( $index['name'], 'sqlite_autoindex_' ) )
			{
				continue;
			}

			$sql = "PRAGMA index_info({$index['name']})";
			$columns = $this->_Adapter->fetchAll( $sql );

			$indexInfo = [
				'name' => $index['name'],
				'columns' => array_column( $columns, 'name' )
			];

			if( $index['unique'] == 1 )
			{
				$indexInfo['unique'] = true;
			}

			$indexes[] = $indexInfo;
		}

		return $indexes;
	}

	/**
	 * Get foreign keys for a table
	 *
	 * @param string $tableName
	 * @return array
	 */
	private function getForeignKeys( string $tableName ): array
	{
		switch( $this->_AdapterType )
		{
			case 'mysql':
				return $this->getForeignKeysMysql( $tableName );
			case 'pgsql':
				return $this->getForeignKeysPostgres( $tableName );
			case 'sqlite':
				return $this->getForeignKeysSqlite( $tableName );
			default:
				throw new \RuntimeException( "Unsupported adapter type: {$this->_AdapterType}" );
		}
	}

	/**
	 * Get foreign keys for MySQL
	 */
	private function getForeignKeysMysql( string $tableName ): array
	{
		$sql = "SELECT
					CONSTRAINT_NAME,
					COLUMN_NAME,
					REFERENCED_TABLE_NAME,
					REFERENCED_COLUMN_NAME,
					DELETE_RULE,
					UPDATE_RULE
				FROM information_schema.KEY_COLUMN_USAGE
				WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
				ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION";

		// Phinx adapters don't support parameterized queries natively
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [
							$this->_Adapter->getOption( 'name' ),
							$tableName
						] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback
			}
		}

		// Fallback: Use escaping
		if( empty( $rows ) )
		{
			$dbName = $this->_Adapter->getOption( 'name' ) ?? '';
			$dbName = str_replace( ["'", "\\"], ["''", "\\\\"], $dbName );
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );

			$sql = "SELECT
						CONSTRAINT_NAME,
						COLUMN_NAME,
						REFERENCED_TABLE_NAME,
						REFERENCED_COLUMN_NAME,
						DELETE_RULE,
						UPDATE_RULE
					FROM information_schema.KEY_COLUMN_USAGE
					WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$tableName}' AND REFERENCED_TABLE_NAME IS NOT NULL
					ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION";
			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$foreignKeys = [];
		$fkGroups = [];

		foreach( $rows as $row )
		{
			if( !isset( $fkGroups[$row['CONSTRAINT_NAME']] ) )
			{
				$fkGroups[$row['CONSTRAINT_NAME']] = [
					'name' => $row['CONSTRAINT_NAME'],
					'columns' => [],
					'referenced_table' => $row['REFERENCED_TABLE_NAME'],
					'referenced_columns' => [],
					'on_delete' => $row['DELETE_RULE'],
					'on_update' => $row['UPDATE_RULE']
				];
			}

			$fkGroups[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
			$fkGroups[$row['CONSTRAINT_NAME']]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
		}

		foreach( $fkGroups as $fk )
		{
			$fkInfo = [
				'name' => $fk['name'],
				'columns' => $fk['columns'],
				'referenced_table' => $fk['referenced_table'],
				'referenced_columns' => $fk['referenced_columns']
			];

			if( $fk['on_delete'] !== 'NO ACTION' && $fk['on_delete'] !== 'RESTRICT' )
			{
				$fkInfo['on_delete'] = $fk['on_delete'];
			}

			if( $fk['on_update'] !== 'NO ACTION' && $fk['on_update'] !== 'RESTRICT' )
			{
				$fkInfo['on_update'] = $fk['on_update'];
			}

			$foreignKeys[] = $fkInfo;
		}

		return $foreignKeys;
	}

	/**
	 * Get foreign keys for PostgreSQL
	 */
	private function getForeignKeysPostgres( string $tableName ): array
	{
		$sql = "SELECT
					tc.constraint_name,
					kcu.column_name,
					ccu.table_name AS referenced_table,
					ccu.column_name AS referenced_column,
					rc.delete_rule,
					rc.update_rule
				FROM information_schema.table_constraints tc
				JOIN information_schema.key_column_usage kcu
					ON tc.constraint_name = kcu.constraint_name
				JOIN information_schema.constraint_column_usage ccu
					ON ccu.constraint_name = tc.constraint_name
				JOIN information_schema.referential_constraints rc
					ON rc.constraint_name = tc.constraint_name
				WHERE tc.constraint_type = 'FOREIGN KEY'
					AND tc.table_schema = 'public'
					AND tc.table_name = ?
				ORDER BY tc.constraint_name, kcu.ordinal_position";

		// Phinx adapters don't support parameterized queries natively
		$rows = [];
		if( method_exists( $this->_Adapter, 'getConnection' ) )
		{
			try
			{
				$connection = $this->_Adapter->getConnection();
				if( $connection instanceof \PDO )
				{
					$stmt = $connection->prepare( $sql );
					if( $stmt !== false )
					{
						$stmt->execute( [$tableName] );
						$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
					}
				}
			}
			catch( \Exception $e )
			{
				// Fall through to fallback
			}
		}

		// Fallback: Use escaping
		if( empty( $rows ) )
		{
			$tableName = str_replace( ["'", "\\"], ["''", "\\\\"], $tableName );
			$sql = "SELECT
						tc.constraint_name,
						kcu.column_name,
						ccu.table_name AS referenced_table,
						ccu.column_name AS referenced_column,
						rc.delete_rule,
						rc.update_rule
					FROM information_schema.table_constraints tc
					JOIN information_schema.key_column_usage kcu
						ON tc.constraint_name = kcu.constraint_name
					JOIN information_schema.constraint_column_usage ccu
						ON ccu.constraint_name = tc.constraint_name
					JOIN information_schema.referential_constraints rc
						ON rc.constraint_name = tc.constraint_name
					WHERE tc.constraint_type = 'FOREIGN KEY'
						AND tc.table_schema = 'public'
						AND tc.table_name = '{$tableName}'
					ORDER BY tc.constraint_name, kcu.ordinal_position";
			$rows = $this->_Adapter->fetchAll( $sql );
		}

		$foreignKeys = [];
		$fkGroups = [];

		foreach( $rows as $row )
		{
			if( !isset( $fkGroups[$row['constraint_name']] ) )
			{
				$fkGroups[$row['constraint_name']] = [
					'name' => $row['constraint_name'],
					'columns' => [],
					'referenced_table' => $row['referenced_table'],
					'referenced_columns' => [],
					'on_delete' => $row['delete_rule'],
					'on_update' => $row['update_rule']
				];
			}

			$fkGroups[$row['constraint_name']]['columns'][] = $row['column_name'];
			$fkGroups[$row['constraint_name']]['referenced_columns'][] = $row['referenced_column'];
		}

		foreach( $fkGroups as $fk )
		{
			$fkInfo = [
				'name' => $fk['name'],
				'columns' => $fk['columns'],
				'referenced_table' => $fk['referenced_table'],
				'referenced_columns' => $fk['referenced_columns']
			];

			if( $fk['on_delete'] !== 'NO ACTION' )
			{
				$fkInfo['on_delete'] = $fk['on_delete'];
			}

			if( $fk['on_update'] !== 'NO ACTION' )
			{
				$fkInfo['on_update'] = $fk['on_update'];
			}

			$foreignKeys[] = $fkInfo;
		}

		return $foreignKeys;
	}

	/**
	 * Get foreign keys for SQLite
	 */
	private function getForeignKeysSqlite( string $tableName ): array
	{
		$sql = "PRAGMA foreign_key_list({$tableName})";
		$rows = $this->_Adapter->fetchAll( $sql );

		$foreignKeys = [];
		$fkGroups = [];

		foreach( $rows as $row )
		{
			$fkId = $row['id'];

			if( !isset( $fkGroups[$fkId] ) )
			{
				$fkGroups[$fkId] = [
					'name' => "{$tableName}_fk_{$fkId}",
					'columns' => [],
					'referenced_table' => $row['table'],
					'referenced_columns' => [],
					'on_delete' => $row['on_delete'],
					'on_update' => $row['on_update']
				];
			}

			$fkGroups[$fkId]['columns'][] = $row['from'];
			$fkGroups[$fkId]['referenced_columns'][] = $row['to'];
		}

		foreach( $fkGroups as $fk )
		{
			$fkInfo = [
				'name' => $fk['name'],
				'columns' => $fk['columns'],
				'referenced_table' => $fk['referenced_table'],
				'referenced_columns' => $fk['referenced_columns']
			];

			if( $fk['on_delete'] !== 'NO ACTION' )
			{
				$fkInfo['on_delete'] = $fk['on_delete'];
			}

			if( $fk['on_update'] !== 'NO ACTION' )
			{
				$fkInfo['on_update'] = $fk['on_update'];
			}

			$foreignKeys[] = $fkInfo;
		}

		return $foreignKeys;
	}

	/**
	 * Normalize column type names
	 *
	 * @param string $type
	 * @return string
	 */
	private function normalizeType( string $type ): string
	{
		// Map database-specific types to generic types
		$typeMap = [
			'bigint' => 'bigint',
			'int' => 'integer',
			'tinyint' => 'integer',
			'smallint' => 'integer',
			'mediumint' => 'integer',
			'varchar' => 'string',
			'char' => 'string',
			'text' => 'text',
			'longtext' => 'text',
			'mediumtext' => 'text',
			'datetime' => 'datetime',
			'timestamp' => 'timestamp',
			'date' => 'date',
			'time' => 'time',
			'decimal' => 'decimal',
			'float' => 'float',
			'double' => 'float',
			'blob' => 'binary',
			'boolean' => 'boolean',
			'json' => 'json'
		];

		$type = strtolower( trim( preg_replace( '/\([^)]*\)/', '', $type ) ) );

		return $typeMap[$type] ?? $type;
	}

	/**
	 * Disconnect from database
	 */
	public function disconnect(): void
	{
		$this->_Adapter->disconnect();
	}

	/**
	 * Destructor - ensure adapter is disconnected
	 */
	public function __destruct()
	{
		if( isset( $this->_Adapter ) )
		{
			try
			{
				$this->_Adapter->disconnect();
			}
			catch( \Exception $e )
			{
				// Silently handle disconnect errors in destructor
			}
		}
	}
}
