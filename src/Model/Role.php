<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class Role extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $casts = ['permissions' => 'json'];
    protected $guarded = ['id'];
    
    protected static $templateItems = [
        'excepts' => ['id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['role_name'],
        'langs' => ['role_view_name', 'description'],
        'children' =>[
            'dashboard_boxes',
        ],
    ];

    protected const EXPORT_TEMPLATE_ITEMS = ['role_type', 'role_name', 'role_view_name', 'description', 'permissions'];
    protected const EXPORT_LANG_ITEMS = ['role_name', 'role_view_name', 'description'];

    /**
     * Get atuhority name.
     * @return string
     */
    public function getRoleName($related_type)
    {
        return "role_{$this->suuid}_{$related_type}";
    }

    /**
     * get role loop function and execute callback
     * @param $related_type string "user" or "organization" string.
     */
    public static function roleLoop($related_type, $callback)
    {
        if (!hasTable(System::getTableName()) || !hasTable(static::getTableName())) {
            return;
        }
        if (!System::permission_available()) {
            return;
        }
        
        // get Role setting
        $roles = static::allRecords(function ($record) use ($related_type) {
            if (is_null($related_type) || is_int($related_type)) {
                return $record->role_type == $related_type;
            } else {
                return $record->role_type == $related_type->getValue();
            }
        });
        foreach ($roles as $role) {
            $related_types = [SystemTableName::USER];
            // if use organization, add
            if (System::organization_available()) {
                $related_types[] = SystemTableName::ORGANIZATION;
            }
            foreach ($related_types as $related_type) {
                $callback($role, $related_type);
            }
        }
    }
    
    /**
     * import template
     */
    public static function importTemplate($role, $options = [])
    {
        // Create role. --------------------------------------------------
        $role_type = RoleType::getEnumValue(array_get($role, 'role_type'));
        $obj_role = Role::firstOrNew(['role_type' => $role_type, 'role_name' => array_get($role, 'role_name')]);
        $obj_role->role_type = $role_type;
        $obj_role->role_name = array_get($role, 'role_name');
        $obj_role->role_view_name = array_get($role, 'role_view_name');
        $obj_role->description = array_get($role, 'description');
        $obj_role->default_flg = boolval(array_get($role, 'default_flg'));

        // Create role detail.
        if (array_key_exists('permissions', $role)) {
            $permissions = [];
            foreach (array_get($role, "permissions") as $permission) {
                $permissions[$permission] = "1";
            }
            $obj_role->permissions = $permissions;
        }
        $obj_role->saveOrFail();

        return $obj_role;
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
}
