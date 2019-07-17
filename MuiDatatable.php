<?php 

namespace App\Custom;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * This class is responsible to manage mui datatables
 * server side processing
 * 
 * @author Kepler Vital <keplervital@hotmail.com>
 */
class MuiDatatable
{
    /**
     * The mui column format
     * 
     * @var array
     */
    private $_columnArrayFormat = [
        'name'     => null,
        'label'    => null,
        'display'  => true,
        'download' => true,
        'filter'   => true,
        'sort'     => true, 
        'sortDirection' => null,
        'viewColumns'   => true
    ];

    /**
     * All column option keys
     * 
     * @var array
     */
    protected $_columnOptionsKeys = [
        'display', 'filterList', 'filterOptons', 'filter',
        'sort', 'sortDirection', 'download', 'hint',
        'customHeadRender', 'customBodyRender', 'setCellProps'
    ];

    protected $_softDeleteColumn = 'deleted_at';
    protected $_instance;
    protected $_table;
    protected $_columns;
    protected $_options;
    protected $_searchText  = '';
    protected $_filterList  = [];
    protected $_rowsPerPage = 10;
    protected $_rowsPerPageOptions = [10, 25, 50, 100];
    protected $_totalRows  = 0;
    protected $_page       = 0;
    protected $_clauses    = [];
    protected $_clausesRaw = [];
    protected $_orClauses  = [];
    
    /**
     * Sets all the base needed to create the mui datatable
     * 
     * @param string $table is the name of the table/view on the database 
     * @param array $columns the columns to show and select data
     * @param array $options the options to use on filters
     *
     */
    public function __construct($table, $columns, $options = [])
    {
        $this->setTable($table)
             ->setOptions($options)
             ->setColumns($columns)
             ->defineOptionsChange();
    }
    /**
     * Determines the default sorting direction
     * and column
     * 
     * @return MuiDatatable with the current object
     */
    protected function setDefaultSorting() {
        $sortingSet = false;
        $columns    = (object)$this->_columns;
        foreach($columns as $column) {
            $column = (object)$column;
            if(!empty($column->sortDirection)) {
                $sortingSet = true;
                break;
            }
        }
        if(!$sortingSet && !empty($columns)) {
            $this->columnSortBy($columns->{'0'}['name']);
        }
        return $this;
    }

    /**
     * Sets the table to sort by
     * 
     * @param string $name column name
     * @param string $direction sort direction enum('asc', 'desc')
     * @return MuiDatatable with the current object
     */
    public function columnSortBy($name, $direction = 'asc') {
        $this->_columns = array_map(function($r) use ($name, $direction) {
            if($r['name'] === $name) {
                $r['sortDirection'] = $direction;
            } else if(!empty($r['sortDirection'])) {
                $r['sortDirection'] = null;
            }
            return $r;
        }, $this->_columns);
        return $this;
    }

    /**
     * Enables/Disables the column download option
     * 
     * @param string $name column name
     * @param boolean $enable flag 
     * @return MuiDatatable with the current object
     */
    public function columnDownload($name, $enable = true) {
        $this->_columns = array_map(function($r) use ($name, $enable) {
            if($r['name'] === $name) {
                $r['download'] = $enable;
            }
            return $r;
        }, $this->_columns);
        return $this;
    }

    /**
     * Enables/Disables the column filter option
     * 
     * @param string $name column name
     * @param boolean $enable flag
     * @return MuiDatatable with the current object
     */
    public function columnFilter($name, $enable = true) {
        $this->_columns = array_map(function($r) use ($name, $enable) {
            if($r['name'] === $name) {
                $r['filter'] = $enable;
            }
            return $r;
        }, $this->_columns);
        return $this;
    }

    /**
     * Changes the column label option
     * 
     * @param string $name column name
     * @param string $default label to set
     * @return MuiDatatable with the current object
     */
    public function columnLabel($name, $default = null) {
        $this->_columns = array_map(function($r) use ($name, $default) {
            if($r['name'] === $name) {
                $r['label'] = !empty($default) ? $default : $r['name'];
            }
            return $r;
        }, $this->_columns);
        return $this;
    }

    /**
     * Sets the table instance do DB driver
     * 
     * @return MuiDatatable with the current object
     */
    protected function tableInstance() {
        $this->_instance = empty($this->_instance) ? DB::table($this->_table) : $this->_instance;
        return $this->_instance;
    }

    /**
     * Gets the column to sort by
     * 
     * @return string name of the column
     */
    protected function sortBy() {
        foreach($this->_columns as $column) {
            $column = (object)$column;
            if(!empty($column->sortDirection)) {
                return $column->name;
            }
        }
        return $this->_columns[0]['name'];
    }

    /**
     * Gets the sort direction asc/desc
     * 
     * @return string sort direction
     */
    protected function sortDirection() {
        foreach($this->_columns as $column) {
            $column = (object)$column;
            if(!empty($column->sortDirection)) {
                return $column->sortDirection;
            }
        }
        return 'asc';
    }

    /**
     * List of columns to select from the database table
     * 
     * @return array columns to select
     */
    protected function selectColumns() {
        $selectList = [];
        foreach($this->_columns as $column) {
            $column = (object)$column;
            $selectList[] = $column->name;
        }
        return $selectList;
    }

    /**
     * The offset to skip rows to allow
     * pagination of the data
     * 
     * @return integer offset to skip
     */
    protected function getOffset() {
        return $this->_page * $this->_rowsPerPage;
    }

    /**
     * The options to change
     * 
     * @return MuiDatatable with the current object
     */
    protected function defineOptionsChange() {
        $options = (object)$this->getOptions();
        $this->_page        = isset($options->page) ? $options->page : $this->_page;
        if($this->_page < 0) {
            $this->_page = 0;
        }
        $this->_rowsPerPage = isset($options->rowsPerPage) ? $options->rowsPerPage : $this->_rowsPerPage;
        $this->_searchText  = isset($options->searchText) ? $options->searchText  : $this->_searchText;
        $this->_filterList  = isset($options->filterList) ? $options->filterList  : $this->_filterList;
        
        if(isset($options->columns)) {
            foreach($options->columns as $column) {
                $column = (object)$column;
                if(!empty($column->sortDirection)) {
                    $this->columnSortBy($column->name, $column->sortDirection);
                    break;
                }
            }
        }

        return $this;
    }
    
    /**
     * Makes all the where clauses to filter
     * 
     * @return MuiDatatable with the current object
     */
    protected function whereClause() {
        $instance = $this->tableInstance();
        if(!empty($this->_softDeleteColumn)) {
            $instance = $instance->whereNull("{$this->_table}.{$this->_softDeleteColumn}");
        }
        foreach($this->_clauses as $whereClause) {
            $instance = $instance->where(
                $whereClause[0],
                $whereClause[1],
                $whereClause[2]
            );
        }

        foreach($this->_clausesRaw as $whereClause) {
            $instance = $instance->whereRaw(
                $whereClause[0],
                $whereClause[1]
            );
        }

        if(!empty($this->_orClauses)) 
        {
            $instance = $instance->where(function ($query) {
                foreach($this->_orClauses as $whereClause) {
                    $query->orWhere(
                        $whereClause[0],
                        $whereClause[1],
                        $whereClause[2]
                    );
                }
            });
        }

        $instance = $instance->where(function ($query) {
            foreach($this->_columns as $column) {
                $column = (object)$column;
                if(!empty($this->_searchText))
                    $query->orWhere("{$this->_table}.{$column->name}", 'ilike', '%'.$this->_searchText.'%');
            }
            for($index = 0; $index < count($this->_filterList); $index++) {
                $filter = $this->_filterList[$index];
                if(isset($this->_columns[$index]) && !empty($filter)) {
                    $column = (object)$this->_columns[$index];
                    foreach($filter as $searchFilter) {
                        $query->orWhere("{$this->_table}.{$column->name}", 'ilike', '%'.$searchFilter.'%');
                    }
                } 
            }
        });
        return $instance;
    }

    /**
     * Sets all the where clauses that must be forced into the query
     * 
     * @param array $whereClauses with the where clauses to set
     * @return MuiDatatable with the current object
     */
    public function addForcedWhereClauses(...$whereClauses) {
        foreach ($whereClauses as $whereClause) {
            if(
                is_array($whereClause) && 
                count($whereClause) === 3
            ) {
                $this->_clauses[] = $whereClause;
            } else if(
                is_array($whereClause) && 
                count($whereClause) === 2
            ) {
                $this->_clausesRaw[] = $whereClause;
            } else {
                throw new Exception('Cannot set a clause that is not an array with [name, comparator, value]');
            }
        }
    }

    /**
     * Removes all the where clauses that must be forced into the query
     * 
     * @return MuiDatatable with the current object
     */
    public function cleanForcedWhereClauses() {
        $this->_clauses    = [];
        $this->_clausesRaw = [];
        return $this;
    }

    /**
     * Sets all the where clauses that must be forced into the query
     * 
     * @param array $whereClauses with the where clauses to set
     * @return MuiDatatable with the current object
     */
    public function addForcedOrWhereClauses(...$whereClauses) {
        foreach ($whereClauses as $whereClause) {
            if(
                is_array($whereClause) && 
                count($whereClause) === 3
            ) {
                $this->_orClauses[] = $whereClause;
            } else {
                throw new Exception('Cannot set a clause that is not an array with [name, comparator, value]');
            }
        }
    }

    /**
     * Removes all the where clauses that must be forced into the query
     * 
     * @return MuiDatatable with the current object
     */
    public function cleanForcedOrWhereClauses() {
        $this->_orClauses = [];
        return $this;
    }

    /**
     * Sets the table soft delete column or empty
     * to ignore it
     * 
     * @param string $table the table name
     * @return MuiDatatable with the current object
     */
    public function setSoftDelete($column) {
        $this->_softDeleteColumn = !empty($column) ? $column : null;
        return $this;
    }

    /**
     * Gets the table soft delete column
     * 
     * @return string with the table name
     */
    public function getSoftDelete() {
        return $this->_softDeleteColumn;
    }

    /**
     * Sets the table name to fetch the data
     * 
     * @param string $table the table name
     * @return MuiDatatable with the current object
     */
    public function setTable($table) {
        $this->_table = !empty($table) ? $table : [];
        return $this;
    }

    /**
     * Gets the table name used to fetch the data
     * 
     * @return string with the table name
     */
    public function getTable() {
        return $this->_table;
    }

    /**
     * Sets all the columns to show
     * 
     * @param array $columns with the used columns
     * @return MuiDatatable with the current object
     */
    public function setColumns($columns) {
        $this->_columns = !empty($columns) ? $columns : [];
        for($index = 0; $index < count($this->_columns); $index++) {
            if(!is_array($this->_columns[$index])) {
                $this->_columns[$index] = ['name' => $this->_columns[$index]];
            }
            $this->_columns[$index] = array_merge($this->_columnArrayFormat, $this->_columns[$index]);
        }
        return $this->setDefaultSorting();
    }

    /**
     * Gets the columns to show on the datatable
     * 
     * @return array with the available columns
     */
    public function getColumns() {
        return $this->_columns;
    }

    /**
     * Gets the columns to show on the datatable
     * with the options set
     * 
     * @return array with the available columns
     */
    public function getColumnsWithOptions() {
        $columns = array();
        $index = 0;
        foreach($this->_columns as $column) {
            $row = array();
            foreach($column as $key => $value) {
                if(!isset($row['options'])) {
                    $row['options'] = array();
                    $row['options']['filterList'] = [];
                    if(isset($this->_filterList[$index])) {
                        $row['options']['filterList'] = $this->_filterList[$index];
                    }
                }
                if(in_array($key, $this->_columnOptionsKeys)) {
                    $row['options'][$key] = $value;
                } else {
                    $row[$key] = $value;
                }
            }
            array_push($columns, $row);
            $index++;
        }
        return $columns;
    }

    /**
     * Sets the available options
     * 
     * @param array $options with the current options
     * @return MuiDatatable with the current object
     */
    public function setOptions($options) {
        $this->_options = !empty($options) ? $options : [];
        return $this;
    }

    /**
     * Get a list of options provided by the mui datatable
     * 
     * @return array with the options
     */
    public function getOptions() {
        return $this->_options;
    }

    /**
     * Responsible for filtering and getting the data
     * for the mui datatable
     * 
     * @return array with the formatted mui data
     */
    public function response() {
        $instance = $this->tableInstance();
        $instance = $this->whereClause();
        $this->_totalRows = $instance->count();
        $instance = $instance->orderBy(
                        $this->sortBy(), 
                        $this->sortDirection()
                    );
        $instance = $instance->select($this->selectColumns());

        //paginating results
        $instance = $instance->skip($this->getOffset())
                             ->take($this->_rowsPerPage);

        //mapping rows to remove column keys
        $rows = array_map(function($row) {
            return array_values(get_object_vars($row));
        }, $instance->get()->toArray());

        $paginationOptions = array_merge(array($this->_rowsPerPage), $this->_rowsPerPageOptions);
        $paginationOptions = array_unique($paginationOptions);
        sort($paginationOptions);

        return [
            'data'    => $rows,
            'columns' => $this->getColumnsWithOptions(),
            'options' => [
                'count' => $this->_totalRows,
                'page'  => $this->_page,
                'rowsPerPage' => $this->_rowsPerPage,
                'rowsPerPageOptions' => $paginationOptions
            ]
        ];
    }

}