<?php

namespace Pimgento\Product\Model\Factory\Import;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Pimgento\Product\Helper\Media as mediaHelper;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Magento\Framework\DB\Adapter\AdapterInterface;
use \Magento\Framework\DB\Ddl\Table;
use \Magento\Catalog\Model\Product\Image;
use \Zend_Db_Expr as Expr;

class Media extends Factory
{

    /**
     * @var \Pimgento\Product\Helper\Media
     */
    protected $_mediaHelper;

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var Image $image
     */
    protected $image;

    /**
     * PHP Constructor
     *
     * @param \Pimgento\Import\Helper\Config                     $helperConfig
     * @param \Magento\Framework\Event\ManagerInterface          $eventManager
     * @param \Magento\Framework\Module\Manager                  $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Pimgento\Entities\Model\Entities                  $entities
     * @param \Pimgento\Product\Helper\Media                     $mediaHelper
     * @param \Magento\Catalog\Model\Product\Image               $image
     * @param array                                              $data
     */
    public function __construct(
        helperConfig $helperConfig,
        ManagerInterface $eventManager,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        Entities $entities,
        mediaHelper $mediaHelper,
        Image $image,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);

        $this->_entities = $entities;
        $this->_mediaHelper = $mediaHelper;
        $this->image = $image;
    }

    /**
     * Clean the media folder
     *
     * @return boolean
     */
    public function cleanMediaFolder()
    {
        if ($this->_mediaHelper->isCleanFiles()) {
            $this->_mediaHelper->cleanFiles();
        }

        return true;
    }

    /**
     * Create the temporary tables needed bby related product import
     *
     * @return void
     */
    public function mediaCreateTmpTables()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tableMedia = $this->_entities->getTableName('media');

        $connection->dropTable($tableMedia);

        $table = $connection->newTable($tableMedia);
        $table->addColumn('sku', Table::TYPE_TEXT, 255, []);
        $table->addColumn('entity_id', Table::TYPE_INTEGER, 10, ['unsigned' => true]);
        $table->addColumn('attribute_id', Table::TYPE_SMALLINT, 5, ['unsigned' => true]);
        $table->addColumn('store_id', Table::TYPE_SMALLINT, 5, ['unsigned' => true]);
        $table->addColumn('value_id', Table::TYPE_INTEGER, 10, ['unsigned' => true]);
        $table->addColumn('record_id', Table::TYPE_INTEGER, 10, ['unsigned' => true]);
        $table->addColumn('media_original', Table::TYPE_TEXT, 255, []);
        $table->addColumn('media_cleaned', Table::TYPE_TEXT, 255, []);
        $table->addColumn('media_value', Table::TYPE_TEXT, 255, []);
        $table->addColumn('position', Table::TYPE_INTEGER, 10, ['unsigned' => true]);
        $table->addIndex(
            $tableMedia.'_entity_id',
            ['entity_id'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $table->addIndex(
            $tableMedia.'_attribute_id',
            ['attribute_id'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $table->addIndex(
            $tableMedia.'_store_id',
            ['store_id'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $table->addIndex(
            $tableMedia.'_value_id',
            ['value_id'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $table->addIndex(
            $tableMedia.'_record_id',
            ['record_id'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $table->addIndex(
            $tableMedia.'_media_value',
            ['media_value'],
            ['type' => AdapterInterface::INDEX_TYPE_INDEX]
        );
        $connection->createTable($table);
    }

    /**
     * Drop the temporary tables needed by media product import
     *
     * @return void
     */
    public function mediaDropTmpTables()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tableMedia = $this->_entities->getTableName('media');

        $connection->dropTable($tableMedia);
    }


    /**
     * Import one column of media import - Database values
     *
     * @param string $column
     * @param int    $attributeId
     * @param int    $position
     *
     * @return boolean
     */
    public function mediaPrepareValues($column, $attributeId, $position)
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());
        $tableMedia = $this->_entities->getTableName('media');

        if (is_null($attributeId)) {
            $attributeId = 'NULL';
        }

        $select = $connection->select()
            ->from(
                ['t' => $tmpTable],
                [
                    'sku'            => 't.sku',
                    'entity_id'      => 't._entity_id',
                    'attribute_id'   => new Expr($attributeId),
                    'store_id'       => new Expr('0'),
                    'media_original' => "t.$column",
                    'position'       => new Expr($position),
                ]
            )->where("`t`.`$column` <> ''");

        $query = $connection->insertFromSelect(
            $select,
            $tableMedia,
            ['sku', 'entity_id', 'attribute_id', 'store_id', 'media_original', 'position'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );

        $connection->query($query);

        return true;
    }

    /**
     * Clean the values to import for medias :
     * - Remove files/ and /files/ from the beginning of the value
     * - remove bad chars
     * - change folder separator into -
     *
     * @return void
     */
    public function mediaCleanValues()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tableMedia = $this->_entities->getTableName('media');

        $expr = "REPLACE(REPLACE(REPLACE(LOWER(`media_original`), '/', '-'), '-files-', ''), 'files-', '')";
        $connection->update($tableMedia, ['media_cleaned' => new Expr($expr)]);

        $expr = "TRIM(CONCAT_WS('-', LOWER(`sku`), `media_cleaned`))";
        $connection->update($tableMedia, ['media_cleaned' => new Expr($expr)]);

        $expr = "LEFT(REPLACE(`media_cleaned`, '-', ''), 4)";
        $connection->update($tableMedia, ['media_value' => new Expr($expr)]);

        $expr = "CONCAT_WS('/', '', SUBSTR(`media_value`, 1, 1), SUBSTR(`media_value`, 2, 1), `media_cleaned`)";
        $connection->update($tableMedia, ['media_value' => new Expr($expr)]);

        $connection->addColumn(
            $tableMedia,
            'id',
            [
                'type'     => Table::TYPE_INTEGER,
                'length'   => 10,
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
                'comment'  => 'primary key',
            ]
        );
    }

    /**
     * Get the max id to treat for media import
     *
     * @param string $tableName
     * @param string $columnId
     *
     * @return int
     */
    public function mediaGetMaxId($tableName, $columnId)
    {
        $connection = $this->_entities->getResource()->getConnection();

        $select = $connection->select()
            ->from(
                ['t' => $tableName],
                ['max_id' => new Expr('MAX('.$columnId.')')]
            );

        $values = $connection->fetchAll($select);

        return (int)$values[0]['max_id'];

    }

    /**
     * Remove the lines that corresponds to unknown files on disc
     *
     * @return void
     */
    public function mediaRemoveUnknownFiles()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tableMedia = $this->_entities->getTableName('media');

        $maxId = $this->mediaGetMaxId($tableMedia, 'id');
        if ($maxId < 1) {
            return;
        }

        $step = 5000;
        for ($k = 1; $k <= $maxId; $k += $step) {
            $min = $k;
            $max = $k + $step;
            $select = $connection->select()
                ->from(
                    ['t' => $tableMedia],
                    [
                        'id'   => 'id',
                        'file' => 'media_original',
                    ]
                )->where("id >= $min AND id < $max");
            $medias = $connection->fetchAll($select);

            $idsToDelete = [];
            foreach ($medias as $media) {
                $file = $this->_mediaHelper->getImportFolder().$media['file'];
                if (!is_file($file)) {
                    $idsToDelete[] = (int)$media['id'];
                }
            }

            if (count($idsToDelete)) {
                $connection->delete($tableMedia, $connection->quoteInto('id IN (?)', $idsToDelete));
            }
        }
    }

    /**
     * Copy the medias to the media folder of magento
     *
     * @return void
     */
    public function mediaCopyFiles()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tableMedia = $this->_entities->getTableName('media');

        $maxId = $this->mediaGetMaxId($tableMedia, 'id');
        if ($maxId < 1) {
            return;
        }

        $step = 5000;
        for ($k = 1; $k <= $maxId; $k += $step) {
            $min = $k;
            $max = $k + $step;
            $select = $connection->select()
                ->from(
                    ['t' => $tableMedia],
                    [
                        'from' => 'media_original',
                        'to'   => 'media_value',
                    ]
                )->where(
                    "id >= $min AND id < $max"
                );
            $medias = $connection->fetchAll($select);
            foreach ($medias as $media) {
                $from = rtrim($this->_mediaHelper->getImportFolder(), '/').'/'.ltrim($media['from'], '/');
                $to = rtrim($this->_mediaHelper->getMediaAbsolutePath(), '/').'/'.ltrim($media['to'], '/');
                $cleanCache = false;

                // if it does not exist, we pass
                if (!is_file($from)) {
                    continue;
                }

                // create the final folder
                if (!is_dir(dirname($to))) {
                    mkdir(dirname($to), 0775, true);
                }

                // remove the file if it exist
                if (is_file($to)) {
                    if (md5_file($from) !== md5_file($to)) {
                        $cleanCache = true;
                    }
                    unlink($to);
                }

                copy($from, $to);

                // clean the cache only if we need to
                if ($cleanCache) {
                    $this->clearImageCache($media['to']);
                }
            }
        }
    }

    /**
     * Clear image resize cache for every defined types
     *
     * @param string $imageFile
     */
    protected function clearImageCache($imageFile)
    {
        foreach ($this->_mediaHelper->getImageResizeTypes() as $resizeType) {
            if (array_key_exists('type', $resizeType)) {
                $this->image->setDestinationSubdir($resizeType['type']);
            }

            // Used for the size folder
            if (array_key_exists('width', $resizeType)) {
                $this->image->setWidth($resizeType['width']);
            }
            if (array_key_exists('height', $resizeType)) {
                $this->image->setHeight($resizeType['height']);
            }

            // Those parameters are used to calculate the hash in the file path
            if (array_key_exists('aspect_ratio', $resizeType)) {
                $this->image->setKeepAspectRatio($resizeType['aspect_ratio']);
            }
            if (array_key_exists('frame', $resizeType)) {
                $this->image->setKeepFrame($resizeType['frame']);
            }
            if (array_key_exists('transparency', $resizeType)) {
                $this->image->setKeepTransparency($resizeType['transparency']);
            }
            if (array_key_exists('constrain', $resizeType)) {
                $this->image->setConstrainOnly($resizeType['constrain']);
            }
            if (array_key_exists('background', $resizeType)) {
                $this->image->setBackgroundColor($resizeType['background']);
            }

            // Those does not exists in view.xsd ; only keeping them for reference, and maybe future usage ?
            if (array_key_exists('quality', $resizeType)) {
                $this->image->setQuality($resizeType['quality']);
            }
            if (array_key_exists('angle', $resizeType)) {
                $this->image->setAngle($resizeType['angle']);
            }

            $this->image->setBaseFile($imageFile);
            $imagePath = $this->image->getNewFile();
            $this->_mediaHelper->deleteMediaFile($imagePath);
        }
    }

    /**
     * Update the media database
     *
     * @return void
     */
    public function mediaUpdateDataBase()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tableMedia = $this->_entities->getTableName('media');
        $step = 5000;

        // add the media in the varchar product table
        $select = $connection->select()
            ->from(
                ['t' => $tableMedia],
                [
                    'attribute_id' => 'attribute_id',
                    'store_id'     => 'store_id',
                    'entity_id'    => 'entity_id',
                    'value'        => 'media_value',
                ]
            )
            ->where('t.attribute_id is not null');

        $identifier = $this->_entities->getColumnIdentifier(
            $resource->getTable('catalog_product_entity_varchar')
        );

        $query = $connection->insertFromSelect(
            $select,
            $resource->getTable('catalog_product_entity_varchar'),
            ['attribute_id', 'store_id', $identifier, 'value'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);

        // working on "media gallery"
        $tableGallery = $resource->getTable('catalog_product_entity_media_gallery');

        // get the value id from gallery (for already existing medias)
        $maxId = $this->mediaGetMaxId($tableGallery, 'value_id');
        for ($k = 1; $k <= $maxId; $k += $step) {
            $min = $k;
            $max = $k + $step;
            // @todo use Zend methods for mass update
            $query = "
                UPDATE $tableMedia, $tableGallery
                SET $tableMedia.value_id = $tableGallery.value_id
                WHERE $tableGallery.value_id >= $min AND $tableGallery.value_id < $max
                AND BINARY $tableGallery.value = $tableMedia.media_value
            ";
            $connection->query($query);
        }

        // add the new medias to the gallery
        $select = $connection->select()
            ->from(
                ['t' => $tableMedia],
                [
                    'attribute_id' => new Expr($this->_mediaHelper->getMediaGalleryAttributeId()),
                    'value'        => 'media_value',
                    'media_type'   => new Expr("'image'"),
                    'disabled'     => new Expr('0'),
                ]
            )->where(
                't.value_id IS NULL'
            )->group('t.media_value');

        $query = $connection->insertFromSelect(
            $select,
            $tableGallery,
            ['attribute_id', 'value', 'media_type', 'disabled'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);

        // get the value id from gallery (for new medias)
        $maxId = $this->mediaGetMaxId($tableGallery, 'value_id');
        for ($k = 1; $k <= $maxId; $k += $step) {
            $min = $k;
            $max = $k + $step;
            // @todo use Zend methods for mass update
            $query = "
                UPDATE $tableMedia, $tableGallery
                SET $tableMedia.value_id = $tableGallery.value_id
                WHERE $tableGallery.value_id >= $min AND $tableGallery.value_id < $max
                AND BINARY $tableGallery.value = $tableMedia.media_value
                AND $tableMedia.value_id IS NULL
            ";
            $connection->query($query);
        }

        // working on "media gallery value"
        $tableGallery = $resource->getTable('catalog_product_entity_media_gallery_value');

        $identifier = $this->_entities->getColumnIdentifier($tableGallery);

        // get the record id from gallery value (for new medias)
        $maxId = $this->mediaGetMaxId($tableGallery, 'record_id');
        for ($k = 1; $k <= $maxId; $k += $step) {
            $min = $k;
            $max = $k + $step;
            // @todo use Zend methods for mass update
            $query = "
                UPDATE $tableMedia, $tableGallery
                SET $tableMedia.record_id = $tableGallery.record_id
                WHERE $tableGallery.record_id >= $min AND $tableGallery.record_id < $max
                AND $tableGallery.".$identifier." = $tableMedia.entity_id
                AND $tableGallery.value_id = $tableMedia.value_id
                AND $tableGallery.store_id = $tableMedia.store_id
            ";
            $connection->query($query);
        }

        // add the new medias to the gallery value
        $select = $connection->select()
            ->from(
                ['t' => $tableMedia],
                [
                    'value_id'  => 'value_id',
                    'store_id'  => new Expr('0'),
                    $identifier => 'entity_id',
                    'label'     => new Expr("''"),
                    'position'  => 'position',
                ]
            )->where(
                't.record_id IS NULL'
            );
        $query = $connection->insertFromSelect(
            $select,
            $tableGallery,
            ['value_id', 'store_id', $identifier, 'label', 'position'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);

        // working on "media gallery value to entity"
        $tableGallery = $resource->getTable('catalog_product_entity_media_gallery_value_to_entity');

        $identifier = $this->_entities->getColumnIdentifier($tableGallery);

        // add the new medias to the gallery linked to entity
        $select = $connection->select()
            ->from(
                ['t' => $tableMedia],
                [
                    'value_id'  => 'value_id',
                    $identifier => 'entity_id',
                ]
            );
        $query = $connection->insertFromSelect(
            $select,
            $tableGallery,
            ['value_id', $identifier],
            AdapterInterface::INSERT_IGNORE
        );
        $connection->query($query);
    }
}
