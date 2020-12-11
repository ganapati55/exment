<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;

/**
 * RelationTable item for Search, linkage, ...
 */
class RelationTable
{
    public $table;
    public $searchType;

    public function __construct(array $params = [])
    {
        $this->table = array_get($params, 'table');
        $this->searchType = array_get($params, 'searchType');
    }
    

    /**
     * Get relation tables list.
     * It contains search_type(select_table, one_to_many, many_to_many)
     */
    public static function getRelationTables($custom_table, $checkPermission = true, $options = [])
    {
        $options = array_merge(
            [
            'search_enabled_only' => true, // if true, filtering search enabled
            ],
            $options
        );

        // check already execute
        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES, $custom_table->table_name);
        return System::requestSession($key, function () use ($custom_table, $options) {
            $results = [];
            // 1. Get tables as "select_table". They contains these columns matching them.
            // * table_column > options > search_enabled is true.
            // * table_column > options > select_target_table is table id user selected.
            $query = CustomTable::whereHas('custom_columns', function ($query) use ($custom_table) {
                $query
                ->withoutGlobalScope(OrderScope::class)
                ->indexEnabled()
                ->selectTargetTable($custom_table->id, strval($custom_table->id));
            });
            if ($options['search_enabled_only']) {
                $query->searchEnabled();
            }
            $tables = $query->get();

            foreach ($tables as $table) {
                $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
                $results[] = new self(['searchType' => SearchType::SELECT_TABLE, 'table' => $table_obj]);
            }

            // 2. Get relation tables.
            // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
            $tables = CustomTable::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
            ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
                ->whereHas('custom_relations', function ($query) use ($custom_table) {
                    $query->where('parent_custom_table_id', $custom_table->id);
                })->get(['child_custom_tables.*', 'custom_relations.relation_type'])->toArray();
            foreach ($tables as $table) {
                $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
                $searchType = array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY;
                $results[] = new self(['searchType' => $searchType, 'table' => $table_obj]);
            }

            return collect($results);
        })->filter(function ($result) use ($checkPermission) {
            // if not role, continue
            if ($checkPermission && !$result->table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Set query as relation filter, all search type
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param mixed $searchType
     * @param mixed $value
     * @param array $params
     * @return mixed
     */
    public static function setQuery($query, $searchType, $value, $params = [])
    {
        $parent_table = CustomTable::getEloquent(array_get($params, 'parent_table'));
        $child_table = CustomTable::getEloquent(array_get($params, 'child_table'));
        
        switch ($searchType) {
            case SearchType::ONE_TO_MANY:
                return static::setQueryOneMany($query, array_get($params, 'parent_table'), $value);
            case SearchType::MANY_TO_MANY:
                return static::setQueryManyMany($query, array_get($params, 'parent_table'), array_get($params, 'child_table'), $value);
            case SearchType::SELECT_TABLE:
                $custom_column = CustomColumn::getEloquent(array_get($params, 'custom_column'));
                if (\is_nullorempty($custom_column) && !\is_nullorempty($child_table)) {
                    $custom_column = $child_table->getSelectTableColumns($parent_table)->first();
                }
                return static::setQuerySelectTable($query, $custom_column, $value);
            }
        
        return $query;
    }

    /**
     * Set query as relation filter for 1:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param mixed $value
     * @return mixed
     */
    public static function setQueryOneMany($query, $parent_table, $value)
    {
        if (is_nullorempty($parent_table)) {
            return;
        }
        
        $query->whereOrIn("parent_id", $value)->where('parent_type', $parent_table->table_name);
        
        return $query;
    }


    /**
     * Set query as relation filter for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param CustomTable $child_table
     * @param mixed $value
     * @return mixed
     */
    public static function setQueryManyMany($query, $parent_table, $child_table, $value)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        if (is_nullorempty($relation)) {
            return;
        }
        
        $query->whereHas($relation->getRelationName(), function ($query) use ($relation, $value) {
            $query->whereOrIn($relation->getRelationName() . '.parent_id', $value);
        });
        
        return $query;
    }

    /**
     * Set query as relation filter for select table
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomColumn $custom_column select_table's column in $query's tbale
     * @param mixed $value
     * @return mixed
     */
    public static function setQuerySelectTable($query, $custom_column, $value)
    {
        if (is_nullorempty($custom_column)) {
            return;
        }
        
        $query->whereOrIn($custom_column->getQueryKey(), $value);

        return $query;
    }
}
