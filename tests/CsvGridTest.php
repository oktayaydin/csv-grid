<?php

namespace yii2tech\tests\unit\csvgrid;

use Yii;
use yii\data\ArrayDataProvider;
use yii\db\Query;
use yii\i18n\Formatter;
use yii2tech\csvgrid\CsvGrid;
use yii2tech\csvgrid\DataColumn;
use yii2tech\csvgrid\ExportResult;
use yii2tech\csvgrid\SerialColumn;

class CsvGridTest extends TestCase
{
    /**
     * Setup tables for test ActiveRecord
     */
    protected function setupTestDbData()
    {
        $db = Yii::$app->getDb();

        // Structure :

        $table = 'Item';
        $columns = [
            'id' => 'pk',
            'name' => 'string',
            'number' => 'integer',
        ];
        $db->createCommand()->createTable($table, $columns)->execute();

        $db->createCommand()->batchInsert($table, ['name', 'number'], [
            ['first', 1],
            ['second', 2],
            ['third', 3],
        ])->execute();
    }

    /**
     * @param array $config CSV grid configuration
     * @return CsvGrid CSV grid instance
     */
    protected function createCsvGrid(array $config = [])
    {
        if (!isset($config['dataProvider']) && !isset($config['query'])) {
            $config['dataProvider'] = new ArrayDataProvider();
        }
        return new CsvGrid($config);
    }

    // Tests :

    public function testSetupFormatter()
    {
        $grid = $this->createCsvGrid();

        $formatter = new Formatter();
        $grid->setFormatter($formatter);
        $this->assertSame($formatter, $grid->getFormatter());
    }

    public function testInitColumns()
    {
        $grid = $this->createCsvGrid([
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [
                    [
                        'id' => 1,
                        'name' => 'first',
                        'description' => 'first description',
                    ],
                    [
                        'id' => 2,
                        'name' => 'second',
                        'description' => 'second description',
                    ],
                ],
            ]),
            'columns' => [
                ['class' => SerialColumn::class],
                'id',
                'name:text',
                [
                    'attribute' => 'description'
                ],
            ],
        ]);
        $grid->export();

        $this->assertCount(4, $grid->columns);
        list($serialColumn, $idColumn, $nameColumn, $descriptionColumn) = $grid->columns;

        $this->assertTrue($serialColumn instanceof SerialColumn);
        /* @var $idColumn DataColumn */
        /* @var $nameColumn DataColumn */
        /* @var $descriptionColumn DataColumn */
        $this->assertTrue($idColumn instanceof DataColumn);
        $this->assertSame('id', $idColumn->attribute);
        $this->assertSame('raw', $idColumn->format);
        $this->assertTrue($nameColumn instanceof DataColumn);
        $this->assertSame('name', $nameColumn->attribute);
        $this->assertSame('text', $nameColumn->format);
        $this->assertTrue($descriptionColumn instanceof DataColumn);
        $this->assertSame('description', $descriptionColumn->attribute);
        $this->assertSame('raw', $descriptionColumn->format);
    }

    public function testExport()
    {
        $grid = $this->createCsvGrid([
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [
                    [
                        'id' => 1,
                        'name' => 'first',
                    ],
                    [
                        'id' => 2,
                        'name' => 'second',
                    ],
                ],
            ])
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertNotEmpty($result->csvFiles, 'Empty result.');
        $fileName = $result->getResultFileName();
        $this->assertFileExists($fileName, 'Result file does not exist.');

        $this->assertContains('"Id","Name"', file_get_contents($fileName), 'Header not present in content.');
        $this->assertContains('"1","first"', file_get_contents($fileName), 'Data not present in content.');
        $this->assertContains('"2","second"', file_get_contents($fileName), 'Data not present in content.');
    }

    /**
     * @depends testExport
     */
    public function testCustomExportResult()
    {
        $grid = $this->createCsvGrid([
            'resultConfig' => [
                'class' => CustomExportResult::className(),
            ]
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof CustomExportResult);
    }

    /**
     * @depends testExport
     */
    public function testExportQuery()
    {
        $this->setupTestDbData();

        $query = (new Query())->from('Item');

        $grid = $this->createCsvGrid([
            'query' => $query,
            'batchSize' => 2
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertNotEmpty($result->csvFiles, 'Empty result.');
        $fileName = $result->getResultFileName();
        $this->assertFileExists($fileName, 'Result file does not exist.');

        $this->assertContains('"Id","Name","Number"', file_get_contents($fileName), 'Header not present in content.');
        $this->assertContains('"first","1"', file_get_contents($fileName), 'Data not present in content.');
        $this->assertContains('"second","2"', file_get_contents($fileName), 'Data not present in content.');
    }

    /**
     * @depends testExport
     */
    public function testMaxEntriesPerFile()
    {
        $grid = $this->createCsvGrid([
            'maxEntriesPerFile' => 2,
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [
                    [
                        'id' => 1,
                        'name' => 'first',
                    ],
                    [
                        'id' => 2,
                        'name' => 'second',
                    ],
                ],
            ])
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertCount(2, $result->csvFiles, 'Wrong number of result files.');
    }

    /**
     * @depends testExport
     */
    public function testEmptyResult()
    {
        // Data provider
        $grid = $this->createCsvGrid([
            'maxEntriesPerFile' => 2,
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [],
            ])
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertCount(1, $result->csvFiles, 'Wrong number of result files.');
        $this->assertFileExists($result->csvFiles[0]->name, 'Result file does not exist.');

        // Query
        $this->setupTestDbData();
        $query = (new Query())->from('Item')->where('0=1');
        $grid = $this->createCsvGrid([
            'maxEntriesPerFile' => 2,
            'query' => $query,
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertCount(1, $result->csvFiles, 'Wrong number of result files.');
        $this->assertFileExists($result->csvFiles[0]->name, 'Result file does not exist.');

        // no headers
        $grid = $this->createCsvGrid([
            'showHeader' => false,
            'showFooter' => false,
            'dataProvider' => new ArrayDataProvider([
                'allModels' => [],
            ])
        ]);

        $result = $grid->export();
        $this->assertTrue($result instanceof ExportResult);
        $this->assertCount(1, $result->csvFiles, 'Wrong number of result files.');
        $this->assertFileExists($result->csvFiles[0]->name, 'Result file does not exist.');
    }
}

class CustomExportResult extends ExportResult
{
}