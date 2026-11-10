<?php

namespace Sprint\Migration;

use Bitrix\Main\Application;
use ExamplePpp\DbTool\Object\Table;
use ExampleTools\Log\Helpers\LoggerHelper;
use Psr\Log\LoggerInterface;
use Sprint\Migration\Exceptions\RestartException;

class Partition_example_data_source_log_table20250901165304 extends Version
{
    protected $author = "i.shumakow@example.ru";

    protected $description = "Разбить таблицу example_data_source_log на партиции №270239123";

    protected $moduleVersion = "4.18.0";

    public function up()
    {
        $logger = LoggerHelper::getLogger('Ppp PARTITION example_data_source_log', 'migration');

        try {
            $this->doUp($logger);
        } catch (RestartException $exception) {
            throw new RestartException();
        } catch (\Throwable $e) {
            $logger->error($e->getMessage(), ['trace' => $e->getTrace()]);
            $this->outError($e->getMessage());
        }
    }

    private function doUp(LoggerInterface $logger): void
    {
        $batchSize = 1_000_000; // примерный (зависит от идентификаторов) размер пакетов при копировании данных из таблицы
        $partitionColumn = 'ENTITY_TYPE';
        $originalTableName = 'example_data_source_log';
        $copyTableName = $originalTableName . '_copy';
        $backupTableName = $originalTableName . '_backup';
        $dbConnection = Application::getConnection();
        $originalTable = new Table($dbConnection, $originalTableName, $logger);

        if (! isset($this->params['progress']['currentId'])) { // initial
            $copyTable = $originalTable->copyStructure($copyTableName);
            $columnValues = $originalTable->selectDistinct($partitionColumn);
            $copyTable->addColumnToPrimaryKey($partitionColumn);
            $partitionConditions = $originalTable->composePartitionByListColumnsConditions($columnValues);
            $copyTable->partitionByListColumns($partitionColumn, $partitionConditions);
            $this->out('Копия таблицы создана и разбита на партиции.');

            $this->params['progress']['currentId'] = $originalTable->getMinId();
            $this->params['progress']['maxId'] = $originalTable->getMaxId();
            $this->out('Инициализированы параметры шкалы прогресса.');

            $this->params['progress']['secondaryIndexes'] = $copyTable->getSecondaryIndexes();
            $this->out('Скопированы данные вторичных индексов.');

            $secondaryIndexesNames = array_column($this->params['progress']['secondaryIndexes'], 'Key_name');
            $uniqueSecondaryIndexesNames = array_unique($secondaryIndexesNames);
            $copyTable->dropIndexes($uniqueSecondaryIndexesNames);
            $this->out('Вторичные индексы удалены на время выполнения миграции данных.');
        }

        $copyTable ??= new Table($dbConnection, $copyTableName, $logger);

        if ($this->params['progress']['currentId'] <= $this->params['progress']['maxId']) { // loop
            $copyTable->copyBatch($originalTable, $this->params['progress']['currentId'], $batchSize);
            $this->outProgress(
                "Копирование данных",
                $this->params['progress']['currentId'],
                $this->params['progress']['maxId']
            );
            $this->params['progress']['currentId'] += $batchSize;
            $this->restart();
        }

        $this->out('Данные успешно скопированы.');

        $copyTable->restoreSecondaryIndexes($this->params['progress']['secondaryIndexes']);
        $this->out('Вторичные индексы восстановлены.');

        $originalTable->rename($backupTableName);
        $this->out('Оригинальная таблица переименована в бэкап.');

        $originalTableMaxId = $originalTable->getMaxId();
        $copyTableMaxId = $copyTable->getMaxId();
        $this->out('OriginalTableMaxId: ' . $originalTableMaxId . '; CopyTableMaxId: ' . $copyTableMaxId);

        if ($copyTableMaxId < $originalTableMaxId) {
            $startId = $copyTableMaxId + 1;
            $batchSize = $originalTableMaxId - $copyTableMaxId;
            $copyTable->copyBatch($originalTable, $startId, $batchSize);
            $this->out('Скопированы новые данные, добавленные за время выполнения миграции.');
        }

        $copyTable->rename($originalTableName);
        $this->out('Копия таблицы переименована в оригинальную.');
        $this->outSuccess('Миграция успешно выполнена.');
        $this->out('Не забудьте удалить копию таблицы, когда убедитесь, что все работает корректно.');
    }
}
