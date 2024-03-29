<?php

namespace ffhome\framework\controller;

use jianyan\excel\Excel;
use think\facade\Db;
use think\helper\Str;

abstract class CrudController extends BaseController
{
    /**
     * 当前模型
     * @Model
     * @var object
     */
    protected $model;

    /**
     * 当前模型别名
     * @var string
     */
    protected $alias = 'model';

    /**
     * 模板布局, false取消
     * @var string|bool
     */
    protected $layout = 'layout/default';

    /**
     * @var int 默认每页记录数
     */
    protected $defaultPageSize = 15;

    /**
     * 初始化方法
     */
    protected function initialize()
    {
        parent::initialize();
        $this->layout && $this->app->view->engine()->layout($this->layout);
    }

    /**
     * 模板变量赋值
     * @param string|array $name 模板变量
     * @param mixed $value 变量值
     * @return mixed
     */
    public function assign($name, $value = null)
    {
        return $this->app->view->assign($name, $value);
    }

    /**
     * 解析和获取模板内容 用于输出
     * @param string $template
     * @param array $vars
     * @return mixed
     */
    public function fetch($template = '', $vars = [])
    {
        return $this->app->view->fetch($template, $vars);
    }

    /**
     * 重写验证规则
     * @param array $data
     * @param array|string $validate
     * @param array $message
     * @param bool $batch
     * @return array|bool|string|true
     */
    public function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        try {
            parent::validate($data, $validate, $message, $batch);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
        return true;
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            return $this->indexOperate();
        }
        $param = $this->request->param('', null, null);
        return $this->indexPage($param);
    }

    protected function indexPage($param)
    {
        $this->assign('param', $param);
        return $this->fetch();
    }

    protected function indexOperate()
    {
        list($page, $limit, $where) = $this->buildSearchParams();
        $count = $this->getConditionModel($where)
            ->count();
        $list = $this->getConditionModel($where)
            ->page($page, $limit)
            ->field($this->getSearchFields())
            ->order($this->getSearchSort())
            ->select()->toArray();
        return $this->successPage($count, $list);
    }

    protected function buildSearchParams()
    {
        $param = $this->request->param('', null, null);
        $page = isset($param['page']) && !empty($param['page']) ? $param['page'] : 1;
        $limit = isset($param['limit']) && !empty($param['limit']) ? $param['limit'] : $this->defaultPageSize;
        unset($param['page']);
        unset($param['limit']);
        $where = $this->buildWhere($param);
        return [$page, $limit, $where];
    }

    protected function buildWhere($param)
    {
        $where = [];
        foreach ($param as $field => $value) {
            if ($value == '') {
                continue;
            }
            if (Str::endsWith($field, '_like')) {
                $where[] = [$this->convertFieldName($field, '_like'), 'LIKE', "%{$value}%"];
            } else if (Str::endsWith($field, '_eq')) {
                $where[] = [$this->convertFieldName($field, '_eq'), '=', $value];
            } else if (Str::endsWith($field, '_ne')) {
                $where[] = [$this->convertFieldName($field, '_ne'), '<>', $value];
            } else if (Str::endsWith($field, '_lt')) {
                $where[] = [$this->convertFieldName($field, '_lt'), '<', $value];
            } else if (Str::endsWith($field, '_time_le')) {
                $where[] = [$this->convertFieldName($field, '_le'), '<', date('Y-m-d', strtotime($value . '+1 day'))];
            } else if (Str::endsWith($field, '_le')) {
                $where[] = [$this->convertFieldName($field, '_le'), '<=', $value];
            } else if (Str::endsWith($field, '_gt')) {
                $where[] = [$this->convertFieldName($field, '_gt'), '>', $value];
            } else if (Str::endsWith($field, '_ge')) {
                $where[] = [$this->convertFieldName($field, '_ge'), '>=', $value];
            } else if (Str::endsWith($field, '_in')) {
                $where[] = [$this->convertFieldName($field, '_in'), 'in', $value];
            } else if (Str::endsWith($field, '_find_in_set')) {
                $where[] = [$this->convertFieldName($field, '_find_in_set'), 'find in set', $value];
            } else if (Str::endsWith($field, '_null')) {
                $where[] = [$this->convertFieldName($field, '_null'), 'exp', Db::raw($value == 1 ? 'is null' : 'is not null')];
            } else if (Str::endsWith($field, '_empty')) {
                $where[] = [$this->convertFieldName($field, '_empty'), $value == 1 ? '=' : '<>', ''];
            } else if (Str::endsWith($field, '_zero')) {
                if ($value == 1) {
                    $where[] = [$this->convertFieldName($field, '_zero'), '=', 0];
                } else {
                    $where[] = [$this->convertFieldName($field, '_zero'), '<>', 0];
                }
            } else if (Str::endsWith($field, '_range')) {
                $f = $this->convertFieldName($field, '_range');
                [$beginTime, $endTime] = explode(' - ', $value);
                $where[] = [$f, '>=', $beginTime];
                $where[] = [$f, '<=', $endTime];
            } else if (Str::endsWith($field, '_or')) {
                $f = explode('_or_', Str::substr($field, 0, Str::length($field) - Str::length('_or')));
                $p = [];
                foreach ($f as $name) {
                    $p[$name] = $value;
                }
                $w = $this->buildWhere($p);
                $condition = [];
                foreach ($w as $it) {
                    // TODO:此次只处理基础的条件语句，复杂的后期再增加
                    $condition[] = "{$it[0]} {$it[1]} '{$it[2]}'";
                }
                $where[] = Db::raw(implode(' or ', $condition));
            }
        }
        return $where;
    }

    private function convertFieldName($field, $op)
    {
        $pos = strpos($field, '_');
        if ($pos !== false) {
            $field[$pos] = '.';
        }
        $field = Str::substr($field, 0, Str::length($field) - Str::length($op));
        return $field;
    }

    protected function getConditionModel($where)
    {
        $m = $this->getSearchModel();
        $m = $m->where($where);
        if (!empty($this->model)
            && method_exists($this->model, 'getDeleteTime')
            && !empty($this->model->getDeleteTime())) {
            $m = $m->whereNull($this->alias . '.' . $this->model->getDeleteTime());
        }
        return $m;
    }

    protected function getSearchModel()
    {
        return $this->model;
    }

    protected function getSearchFields()
    {
        return '*';
    }

    protected function getSearchSort()
    {
        $order = $this->request->get('order', '');
        if (empty($order)) {
            return $this->getSearchDefaultSort();
        }
        $field = $this->request->get('field', '');
        return [$field => $order];
    }

    protected function getSearchDefaultSort()
    {
        return ['id' => 'desc'];
    }

    public function export()
    {
        $header = $this->getExportHeader();
        if (empty($header)) {
            $this->error('请后台设置好导出的表头信息');
        }
        list($page, $limit, $where) = $this->buildSearchParams();
        $list = $this->getConditionModel($where)
            ->limit(100000)
            ->field($this->getSearchFields())
            ->order($this->getSearchSort())
            ->select()->toArray();
        $fileName = date('YmdHis');
        return Excel::exportData($list, $header, $fileName, 'xlsx');
    }

    protected function getExportHeader()
    {
        return null;
    }

    public function add()
    {
        if ($this->request->isAjax()) {
            $this->addOperate();
        }
        return $this->addPage();
    }

    protected function addPage()
    {
        $this->assign('row', $this->getAddDefaultValue([]));
        return $this->fetch($this->getAddPage());
    }

    protected function getAddDefaultValue($row)
    {
        return $this->addInfoInEdit($row);
    }

    protected function addInfoInEdit($row)
    {
        return $row;
    }

    protected function getAddPage()
    {
        return 'edit';
    }

    protected function addOperate()
    {
        $fields = $this->getAddFilterFields();
        if (!empty($fields)) {
            $param = $this->request->only($fields);
        } else {
            $param = $this->request->param();
        }
        $rule = $this->validateAddData($param);
        $this->validate($param, $rule);
        try {
            $this->addBefore($param);
            $save = $this->model->save($param);
            $this->addAfter();
        } catch (\Exception $e) {
            $this->error(lang('common.save_fail') . ':' . $e->getMessage());
        }
        $save ? $this->success(lang('common.save_success')) : $this->error(lang('common.save_fail'));
    }

    protected function addBefore(&$param)
    {
    }

    protected function addAfter()
    {
    }

    protected function getAddFilterFields()
    {
        return $this->getFilterFields();
    }

    protected function getEditFilterFields()
    {
        return $this->getFilterFields();
    }

    protected function getFilterFields()
    {
        return [];
    }

    public function edit($id)
    {
        if ($this->request->isAjax()) {
            $row = $this->getEditOperateModel($id);
            empty($row) && $this->error(lang('common.data_not_exist'));
            $this->editOperate($id, $row);
        }
        $row = $this->getEditPageModel($id);
        empty($row) && $this->error(lang('common.data_not_exist'));
        return $this->editPage($id, $row);
    }

    protected function editPage($id, $row)
    {
        $this->assign('row', $row);
        return $this->fetch();
    }

    protected function getEditPageModel($id)
    {
        $list = $this->getConditionModel([$this->alias . '.id' => $id])
            ->field($this->getEditFields())
            ->limit(1)->select()->toArray();
        return $this->addInfoInEdit($list[0]);
    }

    protected function getEditFields()
    {
        return $this->getSearchFields();
    }

    protected function editOperate($id, $row)
    {
        $fields = $this->getEditFilterFields();
        if (!empty($fields)) {
            $param = $this->request->only($fields);
        } else {
            $param = $this->request->param();
        }
        $rule = $this->validateEditData($param);
        $this->validate($param, $rule);
        try {
            $this->editBefore($param);
            $save = $row->save($param);
            $this->editAfter($row);
        } catch (\Exception $e) {
            $this->error(lang('common.save_fail'));
        }
        $save ? $this->success(lang('common.save_success')) : $this->error(lang('common.save_fail'));
    }

    protected function editBefore(&$param)
    {
    }

    protected function editAfter($row)
    {
    }

    protected function getEditOperateModel($id)
    {
        return $this->model->find($id);
    }

    protected function validateAddData(&$post)
    {
        return $this->validateData($post);
    }

    protected function validateEditData(&$post)
    {
        return $this->validateData($post);
    }

    protected function validateData(&$post)
    {
        return [];
    }

    public function delete($id)
    {
        $row = $this->onBeforeDelete($id);
        try {
            $save = $row->delete();
            $this->onAfterDelete($row->toArray());
        } catch (\Exception $e) {
            $this->error(lang('common.delete_fail'));
        }
        $save ? $this->success(lang('common.delete_success')) : $this->error(lang('common.delete_fail'));
    }

    protected function onBeforeDelete($id)
    {
        $row = $this->model->whereIn('id', $id)->select();
        $row->isEmpty() && $this->error(lang('common.data_not_exist'));
        return $row;
    }

    protected function onAfterDelete($row)
    {
    }
}