<?php

namespace Bolt\Storage\Field\Type;

use Bolt\Storage\Mapping\ClassMetadata;
use Bolt\Storage\Mapping\TaxonomyValue;
use Bolt\Storage\Query\QueryInterface;
use Bolt\Storage\QuerySet;
use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * This is one of a suite of basic Bolt field transformers that handles
 * the lifecycle of a field from pre-query to persist.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 */
class TaxonomyType extends FieldTypeBase
{
    use TaxonomyTypeTrait;

    /**
     * Taxonomy fields allows queries on the parameters passed in.
     * For example the following queries:
     *     'pages', {'categories'=>'news'}
     *     'pages', {'categories'=>'news || events'}.
     *
     * Because the search is actually on the join table, we replace the
     * expression to filter the join side rather than on the main side.
     *
     * @param QueryInterface $query
     * @param ClassMetadata  $metadata
     */
    public function query(QueryInterface $query, ClassMetadata $metadata)
    {
        $field = $this->mapping['fieldname'];

        foreach ($query->getFilters() as $filter) {
            if ($filter->getKey() == $field) {

                // This gets the method name, one of andX() / orX() depending on type of expression
                $method = strtolower($filter->getExpressionObject()->getType()).'X';

                $newExpr = $query->getQueryBuilder()->expr()->$method();
                foreach ($filter->getParameters() as $k => $v) {
                    $newExpr->add("$field.slug = :$k");
                }

                $filter->setExpression($newExpr);
            }
        }
    }

    /**
     * For the taxonomy field the load event modifies the query to fetch taxonomies related
     * to a content record from the join table.
     *
     * It does this via an additional ->addSelect() and ->leftJoin() call on the QueryBuilder
     * which includes then includes the taxonomies in the same query as the content fetch.
     *
     * @param QueryBuilder  $query
     * @param ClassMetadata $metadata
     */
    public function load(QueryBuilder $query, ClassMetadata $metadata)
    {
        $field = $this->mapping['fieldname'];
        $target = $this->mapping['target'];
        $boltname = $metadata->getBoltName();

        if ($this->mapping['data']['has_sortorder']) {
            $order = "$field.sortorder";
            $query->addSelect("$field.sortorder as " . $field . '_sortorder');
        } else {
            $order = "$field.id";
        }

        $from = $query->getQueryPart('from');

        if (isset($from[0]['alias'])) {
            $alias = $from[0]['alias'];
        } else {
            $alias = $from[0]['table'];
        }

        $query
            ->addSelect("$field.slug as " . $field . '_slug')
            ->addSelect($this->getPlatformGroupConcat("$field.name", $order, $field, $query))
            ->leftJoin($alias, $target, $field, "$alias.id = $field.content_id AND $field.contenttype='$boltname' AND $field.taxonomytype='$field'")
            ->addGroupBy("$alias.id");
    }

    /**
     * {@inheritdoc}
     */
    public function hydrate($data, $entity)
    {
        $group = null;
        $sortorder = null;
        $taxValueProxy = [];
        $field = $this->mapping['fieldname'];
        $values = $entity->getTaxonomy();
        $taxData = $this->mapping['data'];
        $taxData['sortorder'] = isset($data[$field . '_sortorder']) ? $data[$field . '_sortorder'] : 0;
        $taxValues = array_filter(explode(',', $data[$field]));
        foreach ($taxValues as $taxValue) {
            $taxValueProxy[$field . '/' . $data[$field . '_slug']] = new TaxonomyValue($field, $taxValue, $taxData);

            if ($taxData['has_sortorder']) {
                // Previously we only cared about the last one… so yeah
                $index = array_search($data[$field . '_slug'], array_keys($taxData['options']));
                $sortorder = $taxData['sortorder'];
                $group = [
                    'slug'  => $data[$field . '_slug'],
                    'name'  => $taxValue,
                    'order' => $sortorder,
                    'index' => $index ?: 2147483647, // Maximum for a 32-bit integer
                ];
            }
        }

        $values[$field] = !empty($taxValueProxy) ? $taxValueProxy : null;
        $entity->setTaxonomy($values);
        $entity->setGroup($group);
        $entity->setSortorder($sortorder);
    }

    /**
     * {@inheritdoc}
     */
    public function persist(QuerySet $queries, $entity)
    {
        $field = $this->mapping['fieldname'];

        $taxonomy = $entity->getTaxonomy();
        $taxonomy[$field] = $this->filterArray($taxonomy[$field]);

        // Fetch existing taxonomies
        $result = $this->getExisting($entity);

        $existing = array_map(
            function ($el) {
                return $el ? $el['slug'] : [];
            },
            $result ?: []
        );
        $proposed = !empty($taxonomy[$field]) ? $taxonomy[$field] : [];

        $toInsert = array_diff($proposed, $existing);
        $toDelete = array_diff($existing, $proposed);

        $this->appendInsertQueries($queries, $entity, $toInsert);
        $this->appendDeleteQueries($queries, $entity, $toDelete);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'taxonomy';
    }

    /**
     * Get platform specific group_concat token for provided column.
     *
     * @param string       $column
     * @param string       $order
     * @param string       $alias
     * @param QueryBuilder $query
     *
     * @return string
     */
    protected function getPlatformGroupConcat($column, $order, $alias, QueryBuilder $query)
    {
        $platform = $query->getConnection()->getDatabasePlatform()->getName();

        switch ($platform) {
            case 'mysql':
                return "GROUP_CONCAT(DISTINCT $column ORDER BY $order ASC) as $alias";
            case 'sqlite':
                return "GROUP_CONCAT(DISTINCT $column) as $alias";
            case 'postgresql':
                return "string_agg(distinct $column, ',' ORDER BY $order) as $alias";
        }
    }
}
