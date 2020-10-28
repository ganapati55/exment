<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Table as WidgetTable;
use Illuminate\Http\Request;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemRoleType;
use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\System;

class TablePermissionController extends Controller
{
    use ExmentControllerTrait;

    /**
     * @return Grid
     */
    public function getTable(Request $request, $tableKey)
    {
        $custom_table = CustomTable::getEloquent($tableKey);

        return [
            'title' => exmtrans('custom_table.permission.title'),
            'body' => $this->body($custom_table),
            'footer' => null,
            'suuid' => $custom_table->suuid,
            'showSubmit' => false
        ];
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    public function getData(Request $request, $tableKey, $id)
    {
        $custom_table = CustomTable::getEloquent($tableKey);
        $custom_value = $custom_table->getValueModel($id);

        return [
            'title' => exmtrans('custom_table.permission.title'),
            'body' => $this->body($custom_table, $custom_value),
            'footer' => null,
            'suuid' => $custom_value->suuid,
            'showSubmit' => false
        ];
    }
    
    /**
     * get body
     * *this function calls from non-value method. So please escape if not necessary unescape.
     */
    public function body($custom_table, $custom_value = null)
    {
        $result = $this->getAllUserList($custom_table) . $this->getRoleGroupList($custom_table, $custom_value);

        if (isset($custom_value)) {
            $result .= $this->getCustomValueList($custom_value);
        }

        return $result;
    }

    /**
     * get authority list for all user
     */
    protected function getAllUserList($custom_table)
    {
        $headers = [exmtrans('custom_table.permission.all_user')];
        $bodies = [];

        if (boolval($custom_table->getOption('all_user_editable_flg'))) {
            $bodies[] = [exmtrans('custom_table.all_user_editable_flg')];
        }
        if (boolval($custom_table->getOption('all_user_viewable_flg'))) {
            $bodies[] = [exmtrans('custom_table.all_user_viewable_flg')];
        }
        if (boolval($custom_table->getOption('all_user_accessable_flg'))) {
            $bodies[] = [exmtrans('custom_table.all_user_accessable_flg')];
        }
        if (count($bodies) == 0) {
            $bodies[] = [exmtrans('custom_table.permission.row_count0')];
        }

        $widgetTable = new WidgetTable([], $bodies);
        $widgetTable->class('table table-hover');
        $box = new Box(exmtrans('custom_table.permission.all_user_setting'), $widgetTable);
        return $box->render();
    }

    protected function getRoleGroupList($custom_table)
    {
        $table_id = $custom_table->id;

        $datalist = RoleGroupPermission::where(function($query){
            $query->where('role_group_permission_type', RoleType::SYSTEM)
                  ->where('role_group_target_id', SystemRoleType::SYSTEM);
        })->orWhere(function($query) use($table_id){
            $query->where('role_group_permission_type', RoleType::TABLE)
                  ->where('role_group_target_id', $table_id);
        })->get();

        // create headers
        $headers = exmtrans('custom_table.permission.role_group_columns');

        $bodies = [];
        $role_groups = collect();
        
        if (isset($datalist)) {
            foreach ($datalist as $data) {
                $list = $this->getPermissionList($data);
                if (empty($list)) {
                    continue;
                }
                $role_group = $role_groups->get($data->role_group_id);
                if (isset($role_group)) {
                    $role_groups->put($data->role_group_id, array_merge($role_group, $list));
                } else {
                    $role_groups->put($data->role_group_id, $list);
                }
            }

            $bodies = $role_groups->map(function($item, $key) {
                $role_group = RoleGroup::find($key);
                $url = admin_urls('userorganization', "?role_group=$key");
                $link = \Exment::getUrlTag($url, $role_group->role_group_view_name, UrlTagType::TOP);
                $permission_text = implode(exmtrans('common.separate_word'), $item);
                return [
                    $link,
                    $permission_text
                ];
            })->values()->toArray();
        }

        $widgetTable = new WidgetTable($headers, $bodies);
        $widgetTable->class('table table-hover');
        $box = new Box(exmtrans('custom_table.permission.table_setting'), $widgetTable);
        return $box->render();
    }

    protected function getCustomValueList($custom_value)
    {
        $authorities = CustomValueAuthoritable::getListsOnCustomValue($custom_value);

        // create headers
        $headers = exmtrans('custom_table.permission.custom_value_columns');

        $bodies = [];
        
        if (isset($authorities)) {
            $bodies = $authorities->map(function($item, $key) {
                $user_org = $item->authoritable_user_org;
                $authority_type_name = null;
                $link = null;
                $permission_text = exmtrans('role_group.role_type_option_value.'.$item->authoritable_type.'.label');
                if (isset($user_org)) {
                    $custom_table = $user_org->custom_table;
                    $authority_type_name = $custom_table->table_view_name;
                    $url = admin_urls('data', $custom_table->table_name, $user_org->id);
                    $name = $user_org->getValue($custom_table->table_name . '_name');
                    $link = \Exment::getUrlTag($url, $name, UrlTagType::TOP);
                }
                return [
                    $authority_type_name,
                    $link,
                    $permission_text
                ];
            })->values()->toArray();
        }

        $widgetTable = new WidgetTable($headers, $bodies);
        $widgetTable->class('table table-hover');
        $box = new Box(exmtrans('custom_table.permission.share_setting'), $widgetTable);
        return $box->render();
    }

    protected function getPermissionList($data)
    {
        $permission_type = $data->role_group_permission_type;
        $permissions = $data->permissions;

        return collect($permissions)->filter(function($permission) {
            if (isset($permission) && in_array($permission, Permission::TABLE_ROLE_PERMISSION)) {
                return true;
            }
        })->map(function($permission) use($permission_type) {
            if ($permission_type == RoleType::SYSTEM) {
                return exmtrans("role_group.role_type_option_system.$permission.label");
            } else {
                return exmtrans("role_group.role_type_option_table.$permission.label");
            }
        })->toArray();
    }
}
