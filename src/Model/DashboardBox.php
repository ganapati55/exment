<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;

class DashboardBox extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];

    protected static $templateItems = [
        'excepts' => ['id', 'suuid', 'dashboard_id', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'deleted_user_id'],
        'keys' => ['row_no', 'column_no'],
        'langs' => ['dashboard_box_view_name'],
        
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_table_id',
                        'replacedName' => [
                            'table_name' => 'options.target_table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_view_id',
                        'replacedName' => [
                            'suuid' => 'options.target_view_suuid',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomView::class,
            ],
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'options.target_system_id',
                        'replacedName' => [
                            'name' => 'options.target_system_name',
                        ]
                    ]
                ],
                'uniqueKeySystemEnum' => DashboardBoxSystemPage::class,
            ],
        ],
    ];

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    public function getDashboardBoxItemAttribute()
    {
        $enum_class = DashboardBoxType::getEnum($this->dashboard_box_type)->getDashboardBoxItemClass();
        return $enum_class::getItem($this) ?? null;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * import template
     */
    public static function importTemplate($dashboard_box, $options = [])
    {
        // Create dashboard --------------------------------------------------
        $obj_dashboard = array_get($options, 'obj_dashboard');

        // create dashboard boxes --------------------------------------------------
        $obj_dashboard_box = DashboardBox::firstOrNew([
            'dashboard_id' => $obj_dashboard->id,
            'row_no' => array_get($dashboard_box, "row_no"),
            'column_no' => array_get($dashboard_box, "column_no"),
        ]);
        $obj_dashboard_box->dashboard_box_view_name = array_get($dashboard_box, "dashboard_box_view_name");
        $obj_dashboard_box->dashboard_box_type = DashboardBoxType::getEnumValue(array_get($dashboard_box, "dashboard_box_type"));

        // set options
        collect(array_get($dashboard_box, 'options', []))->each(function ($option, $key) use ($obj_dashboard_box) {
            $obj_dashboard_box->setOption($key, $option);
        });
        
        // switch dashboard_box_type
        switch ($obj_dashboard_box->dashboard_box_type) {
            // system box
            case DashboardBoxType::SYSTEM:
                $id = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function ($value) use ($dashboard_box) {
                    return array_get($value, 'name') == array_get($dashboard_box, 'options.target_system_name');
                })['id'] ?? null;
                $obj_dashboard_box->setOption('target_system_id', $id);
                break;
            
            // list
            case DashboardBoxType::LIST:
                // get target table
                $obj_dashboard_box->setOption('target_table_id', CustomTable::getEloquent(array_get($dashboard_box, 'options.target_table_name'))->id ?? null);
                // get target view using suuid
                $obj_dashboard_box->setOption('target_view_id', CustomView::findBySuuid(array_get($dashboard_box, 'options.target_view_suuid'))->id ?? null);
                break;
        }

        $obj_dashboard_box->saveOrFail();

        return $obj_dashboard;
    }
}
