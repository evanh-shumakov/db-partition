<?php

namespace ExamplePpp\DbTool\Object;

use Bitrix\Main\Data\Connection;
use Bitrix\Main\Db\SqlQueryException;
use Psr\Log\LoggerInterface;

class Table
{
    private string $name;

    private ?LoggerInterface $logger;

    /** @var \Bitrix\Main\Data\Connection|\Bitrix\Main\DB\Connection */
    private Connection $dbConnection;

    public function __construct(Connection $dbConnection, string $name, ?LoggerInterface $logger = null)
    {
        $this->name = $name;
        $this->logger = $logger;
        $this->dbConnection = $dbConnection;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMaxId(): ?int
    {
        $getMaxId = 'SELECT MAX(id) FROM ' . $this->name;

        $this->logger && $this->logger->info('try: ' . $getMaxId);

        $maxId = $this->dbConnection->queryScalar($getMaxId);

        $this->logger && $this->logger->info('result: ' . (is_null($maxId) ? 'NULL' : $maxId));

        return $maxId;
    }

    public function getMinId(): ?int
    {
        $getOriginalTableMinId = 'SELECT MIN(id) FROM ' . $this->name;

        $this->logger && $this->logger->info('try: ' . $getOriginalTableMinId);

        $minId = $this->dbConnection->queryScalar($getOriginalTableMinId);

        $this->logger && $this->logger->info('result: ' . (is_null($minId) ? 'NULL' : $minId));

        return $minId;
    }

    public function copyStructure(string $newTableName): Table
    {
        $copyTableStructure = 'CREATE TABLE ' . $newTableName . ' LIKE ' . $this->name;

        $this->logger && $this->logger->info('try: ' . $copyTableStructure);

        $this->dbConnection->queryExecute($copyTableStructure);

        return new Table($this->dbConnection, $newTableName, $this->logger);
    }

    public function selectDistinct(string $partitionColumn): array
    {
        $selectDistinct = 'SELECT DISTINCT ' . $partitionColumn . ' FROM ' . $this->name;

        $this->logger && $this->logger->info('try: ' . $selectDistinct);

        $result = $this->dbConnection->query($selectDistinct);
        $rows = $result->fetchAll();

        $this->logger && $this->logger->info('result: ' . var_export($rows, true));

        $values = [];

        foreach ($rows as $row) {
            $values[] = $row[$partitionColumn];
        }

        return $values;
    }

    public function addColumnToPrimaryKey(string $column): void
    {
        $rows = $this->getPrimaryKey();

        $primaryKeyColumns = array_column($rows, 'Column_name');

        if (in_array($column, $primaryKeyColumns)) {
            $partitionColumnIsPrimaryKey = 'the ' . $column . ' column is a part of a primary key';
            $this->logger && $this->logger->info($partitionColumnIsPrimaryKey);
            return;
        }

        $updatePrimaryKey = 'ALTER TABLE ' . $this->name . ' DROP PRIMARY KEY, ADD PRIMARY KEY ('
            . implode(', ', [...$primaryKeyColumns, $column]) . ')';

        $this->logger && $this->logger->info('try: ' . $updatePrimaryKey);

        $this->dbConnection->queryExecute($updatePrimaryKey);
    }

    /**
     * @return array<array{
     *     Table: string,
     *     Non_unique: int,
     *     Key_name: 'PRIMARY',
     *     Seq_in_index: int,
     *     Column_name: string,
     *     Collation: string,
     *     Cardinality: int,
     *     Index_type: string,
     *  }>
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    public function getPrimaryKey(): array
    {
        $getPrimaryKeyColumns = 'SHOW INDEX FROM ' . $this->name . ' WHERE Key_name = "PRIMARY"';

        $this->logger && $this->logger->info('try: ' . $getPrimaryKeyColumns);

        $result = $this->dbConnection->query($getPrimaryKeyColumns);

        $rows = $result->fetchAll();

        $this->logger && $this->logger->info('result: ' . var_export($rows, true));

        return $rows;
    }

    /**
     * @param array<string> $values
     * @return array<string>
     */
    public function composePartitionByListColumnsConditions(array $values): array
    {
        $conditions = [];

        foreach ($values as $value) {
            $partition = "p_{$value}";
            $conditions[] = "PARTITION {$partition} VALUES IN ('{$value}')";
        }

        return $conditions;
    }

    /**
     * @param array<string> $conditions self::composePartitionByListColumnsConditions(values : array)
     * @return void
     * @throws SqlQueryException
     */
    public function partitionByListColumns(string $column, array $conditions): void
    {
        $partitionByListColumns = 'ALTER TABLE ' . $this->name
            . ' PARTITION BY LIST COLUMNS(' . $column
            . ') (' . implode(', ', $conditions) . ')';

        $this->logger && $this->logger->info('try: ' . $partitionByListColumns);

        $this->dbConnection->queryExecute($partitionByListColumns);
    }

    public function copyBatch(self $source, int $startId, int $batchSize): void
    {
        $endId = $startId + $batchSize - 1;

        $copyBatch = 'INSERT INTO ' . $this->name
            . ' SELECT * FROM ' . $source->name
            . ' WHERE ID BETWEEN ' . $startId . ' AND ' . $endId;

        $this->logger && $this->logger->info('try: ' . $copyBatch);

        $this->dbConnection->queryExecute($copyBatch);
    }

    public function rename(string $newName): void
    {
        $rename = 'RENAME TABLE ' . $this->name . ' TO ' . $newName;

        $this->logger && $this->logger->info('trying: ' . $rename);

        $this->dbConnection->queryExecute($rename);

        $this->name = $newName;
    }

    /**
     * @return array<array{
     *      Table: string,
     *      Non_unique: int,
     *      Key_name: string,
     *      Seq_in_index: int,
     *      Column_name: string,
     *      Collation: string,
     *      Cardinality: int,
     *      Index_type: string,
     *   }>
     * @throws SqlQueryException
     */
    public function getSecondaryIndexes(): array
    {
        $showSecondaryIndexes = 'SHOW INDEX FROM ' . $this->name . ' WHERE Key_name != "PRIMARY" AND Non_unique = 1';

        $this->logger && $this->logger->info('try: ' . $showSecondaryIndexes);

        $result = $this->dbConnection->query($showSecondaryIndexes);

        $indexes = $result->fetchAll();

        $this->logger && $this->logger->info('result: ' . var_export($indexes, true));

        return $indexes;
    }

    /**
     * @param array<string> $indexes
     * @return void
     */
    public function dropIndexes(array $indexes): void
    {
        foreach ($indexes as $index) {
            $this->dropIndex($index);
        }
    }

    public function dropIndex(string $index): void
    {
        $dropIndex = 'ALTER TABLE ' . $this->name . ' DROP INDEX ' . $index;

        $this->logger && $this->logger->info('try: ' . $dropIndex);

        $this->dbConnection->queryExecute($dropIndex);
    }

    /**
     * @param array<array{
     *       Table: string,
     *       Non_unique: int,
     *       Key_name: string,
     *       Seq_in_index: int,
     *       Column_name: string,
     *       Collation: string,
     *       Cardinality: int,
     *       Index_type: string,
     *    }> $secondaryIndexesRows self::getSecondaryIndexes
     * @return void
     */
    public function restoreSecondaryIndexes(array $secondaryIndexesRows): void
    {
        $indexes = [];

        foreach ($secondaryIndexesRows as $row) {
            $indexes[$row['Key_name']][] = $row['Column_name'];
        }

        foreach ($indexes as $name => $columns) {
            $this->addSecondaryIndex($name, $columns);
        }
    }

    /**
     * @param string $name
     * @param array<string> $columns
     * @return void
     */
    public function addSecondaryIndex(string $name, array $columns): void
    {
        $columnsList = implode(', ', $columns);

        $addIndex = 'ALTER TABLE ' . $this->name . ' ADD INDEX ' . $name . ' (' . $columnsList . ')';

        $this->logger && $this->logger->info('try: ' . $addIndex);

        $this->dbConnection->queryExecute($addIndex);
    }
}
