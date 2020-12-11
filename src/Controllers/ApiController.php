<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ErrorCode;
use Validator;

/**
 * Api about target table
 */
class ApiController extends AdminControllerBase
{
    use ApiTrait;
    
    /**
     * get Exment version
     */
    public function version(Request $request)
    {
        return response()->json(['version' => (new \Exceedone\Exment\Exment)->version(false)]);
    }

    /**
     * get login user info
     * @param Request $request
     * @return array|null
     */
    public function me(Request $request)
    {
        $base_user = \Exment::user()->base_user ?? null;
        if (!isset($base_user)) {
            return null;
        }
        $base_user = $base_user->makeHidden(CustomTable::getEloquent(SystemTableName::USER)->getMakeHiddenArray())
            ->toArray();

        if ($request->has('dot') && boolval($request->get('dot'))) {
            $base_user = array_dot($base_user);
        }
        return $base_user;
    }

    /**
     * get table list
     * @return mixed
     */
    public function tablelist(Request $request)
    {
        // if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
        //     return abortJson(403, ErrorCode::PERMISSION_DENY());
        // }

        // get and check query parameter
        if (($count = $this->getCount($request)) instanceof Response) {
            return $count;
        }

        $options = [
            'getModel' => false,
            'with' => $this->getJoinTables($request, 'custom'),
            'permissions' => Permission::AVAILABLE_ACCESS_CUSTOM_VALUE
        ];
        // filterd by id
        if ($request->has('id')) {
            $ids = explode(',', $request->get('id'));
            $options['filter'] = function ($model) use ($ids) {
                $model->whereIn('id', $ids);
                return $model;
            };
        }

        // filter table
        $query = CustomTable::query();
        CustomTable::filterList($query, $options);
        return $query->paginate($count ?? config('exment.api_default_data_count'));
    }

    /**
     * get column list
     * @return mixed
     */
    public function columns(Request $request)
    {
        return $this->_getcolumns($request, false);
    }

    /**
     * get column list
     * @return mixed
     */
    public function indexcolumns(Request $request)
    {
        return $this->_getcolumns($request);
    }

    /**
     * get column list
     * @return mixed
     */
    protected function _getcolumns(Request $request, $onlyIndex = true)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        // if execute as selecting column_type
        if ($request->has('custom_type')) {
            // check user or organization
            if (!ColumnType::isUserOrganization($request->get('q'))) {
                return [];
            }
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        if ($onlyIndex) {
            return CustomTable::getEloquent($table)->custom_columns()->indexEnabled()->get();
        } else {
            return CustomTable::getEloquent($table)->custom_columns()->get();
        }
    }

    /**
     * get filter view list
     * @return mixed
     */
    public function filterviews(Request $request)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        $table = $request->get('q');
        if (!isset($table)) {
            return [];
        }

        // if execute as selecting column_type
        if ($request->has('custom_type')) {
            // check user or organization
            if (!ColumnType::isUserOrganization($table)) {
                return [];
            }
        }
        $table = CustomTable::getEloquent($table);
        if (!isset($table)) {
            return [];
        }

        return CustomView
            ::where('custom_table_id', $table->id)
            ->where('view_kind_type', ViewKindType::FILTER)
            ->get();
    }

    /**
     * get table data by id or table_name
     * @param mixed $tableKey id or table_name
     * @return mixed
     */
    public function table($tableKey, Request $request)
    {
        $withs = $this->getJoinTables($request, 'custom');
        $table = CustomTable::getEloquent($tableKey, $withs);

        if (!isset($table)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }

        if (!$table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        return $table;
    }

    /**
     * get column data by id
     * @param mixed $id
     * @return mixed
     */
    public function column($id, Request $request)
    {
        return $this->responseColumn($request, CustomColumn::find($id));
    }

    /**
     * get view
     * @param mixed $idOrSuuid if length is 20, use suuid
     * @return mixed
     */
    public function view(Request $request, $idOrSuuid)
    {
        $query = CustomView::query();
        if (strlen($idOrSuuid) == 20) {
            $query->where('suuid', $idOrSuuid);
        } else {
            $query->where('id', $idOrSuuid);
        }

        return $query->first();
    }
    


    /**
     * get columns that belongs table using column id
     * 1. find column and get column info
     * 2. get column target table
     * 3. get columns that belongs to target table
     * @param mixed $id select_table custon_column id
     */
    public function targetBelongsColumns($id)
    {
        if (!isset($id)) {
            return [];
        }
        // get custom column
        $custom_column = CustomColumn::getEloquent($id);

        // if column_type is not select_table, return []
        if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
            return [];
        }

        // get select_target_table
        $select_target_table = $custom_column->select_target_table;
        if (!isset($select_target_table)) {
            return [];
        }
        return CustomTable::getEloquent($select_target_table)->custom_columns()->get(['id', 'column_view_name'])->pluck('column_view_name', 'id');
    }

    /**
     * create notify
     */
    public function notifyCreate(Request $request)
    {
        $is_single = false;

        $validator = Validator::make($request->all(), [
            'target_users' => 'required',
            'notify_subject' => 'required',
            'notify_body' => 'required',
        ]);
        if ($validator->fails()) {
            return abortJson(400, [
                'errors' => $this->getErrorMessages($validator)
            ], ErrorCode::VALIDATION_ERROR());
        }

        $target_users = $request->get('target_users');

        if (!is_array($target_users)) {
            $target_users = explode(',', $target_users);
            $is_single = count($target_users) == 1;
        }

        $error_users = collect($target_users)->filter(function ($target_user) {
            return is_null(getModelName(SystemTableName::USER)::find($target_user));
        });

        if ($error_users->count() > 0) {
            return abortJson(400, [
                'errors' => ['target_users' => exmtrans('api.errors.user_notfound', $error_users->implode(','))]
            ], ErrorCode::VALIDATION_ERROR());
        }

        $response = [];

        foreach ($target_users as $target_user) {
            $notify = new NotifyNavbar();
    
            $notify->fill([
                'notify_id' => 0,
                'target_user_id' => $target_user,
                'notify_subject' => $request->get('notify_subject'),
                'notify_body' => $request->get('notify_body'),
                'trigger_user_id' => \Exment::getUserId()
            ]);
    
            $notify->saveOrFail();

            $response[] = $notify;
        }

        if ($is_single && count($response) > 0) {
            return $response[0];
        } else {
            return $response;
        }
    }
    
    /**
     * Get notify List
     *
     * @param Request $request
     * @return void
     */
    public function notifyList(Request $request)
    {
        if (($reqCount = $this->getCount($request)) instanceof Response) {
            return $reqCount;
        }

        // get notify NotifyNavbar list
        $query = NotifyNavbar::where('target_user_id', \Exment::getUserId());
                
        if (!boolval($request->get('all', false))) {
            $query->where('read_flg', false);
        }

        $count = $query->count();
        $paginator = $query->paginate($reqCount);

        // set appends
        $paginator->appends([
            'count' => $count,
        ]);
        if ($request->has('all')) {
            $paginator->appends([
                'all' => $request->get('all'),
            ]);
        }

        return $paginator;
    }

    /**
     * Get notify for page
     *
     * @param Request $request
     * @return void
     */
    public function notifyPage(Request $request)
    {
        // get notify NotifyNavbar list
        $query = NotifyNavbar::where('target_user_id', \Exment::getUserId())
            ->where('read_flg', false);
        
        $count = $query->count();
        $list = $query->take(5)->get();

        return [
            'count' => $count,
            'items' => $list->map(function ($l) {
                $custom_table = CustomTable::getEloquent(array_get($l, 'parent_type'));
                if (isset($custom_table)) {
                    $icon = $custom_table->getOption('icon');
                    $color = $custom_table->getOption('color');
                    $table_view_name = $custom_table->table_view_name;
                }

                return [
                    'id' => array_get($l, 'id'),
                    'icon' => $icon ?? 'fa-bell',
                    'color' => $color ?? null,
                    'table_view_name' => $table_view_name ?? null,
                    'label' => array_get($l, 'notify_subject'),
                    'href' => admin_urls('notify_navbar', $l->id)
                ];
            }),
            'noItemMessage' => exmtrans('notify_navbar.message.no_newitem')
        ];
    }

    /**
     * Get user or organization for select
     *
     * @param Request $request
     * @return void
     */
    public function userOrganizationSelect(Request $request)
    {
        $keys = [SystemTableName::USER];
        if (System::organization_available()) {
            $keys[] = SystemTableName::ORGANIZATION;
        }

        $results = collect();
        // default count
        $count = config('exment.api_default_data_count', 20);
        foreach ($keys as $key) {
            $custom_table = CustomTable::getEloquent($key);

            if (($code = $custom_table->enableAccess()) !== true) {
                return abortJson(403, ErrorCode::PERMISSION_DENY());
            }

            $validator = \Validator::make($request->all(), [
                'q' => 'required',
            ]);
            if ($validator->fails()) {
                return abortJson(400, [
                    'errors' => $this->getErrorMessages($validator)
                ], ErrorCode::VALIDATION_ERROR());
            }

            // filtered query
            $q = $request->get('q');
            
            if (($count = $this->getCount($request)) instanceof Response) {
                return $count;
            }

            $result = $custom_table->searchValue($q, [
                'makeHidden' => true,
                'maxCount' => $count,
            ]);

            // if call as select ajax, return id and text array
            $results = $results->merge(
                $result->map(function ($value) use ($key) {
                    return [
                        'id' => $key . '_' . $value->id,
                        'text' => $value->label,
                    ];
                })
            );
        }

        // get as paginator
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($results, count($results), $count, 1);

        return $paginator;
    }
}
