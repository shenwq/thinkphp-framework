<?php

namespace ffhome\framework\controller;

use jianyan\excel\Excel;
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
        return $this->indexPage();
    }

    protected function indexPage()
    {
        return $this->fetch();
    }

    protected function indexOperate()
    {
        list($page, $limit, $where) = $this->buildSearchParams();
        $count = $this->getConditionModel($where)
            ->count();
        $list = $this->getConditionModel($where)
            ->page($page, $limit)
            ->fieldRaw($this->getSearchFields())
            ->order($this->getSearchSort())
            ->select()->toArray();
        return $this->successPage($count, $list);
    }

    protected function buildSearchParams()
    {
        $get = $this->request->get('', null, null);
        $page = isset($get['page']) && !empty($get['page']) ? $get['page'] : 1;
        $limit = isset($get['limit']) && !empty($get['limit']) ? $get['limit'] : $this->defaultPageSize;
        $where = [];
        unset($get['page']);
        unset($get['limit']);
        foreach ($get as $field => $value) {
            if ($value == '') {
                continue;
            }
            if (Str::endsWith($field, '_like')) {
                $where[] = [$this->convertFieldName($field, '_like'), 'LIKE', "%{$value}%"];
            } else if (Str::endsWith($field, '_eq')) {
                $where[] = [$this->convertFieldName($field, '_eq'), '=', $value];
            } else if (Str::endsWith($field, '_ne')) {
                $where[] = [$this->convertFieldName($field, '_ne'), '!=', $value];
            } else if (Str::endsWith($field, '_lt')) {
                $where[] = [$this->convertFieldName($field, '_lt'), '<', $value];
            } else if (Str::endsWith($field, '_le')) {
                $where[] = [$this->convertFieldName($field, '_le'), '<=', $value];
            } else if (Str::endsWith($field, '_gt')) {
                $where[] = [$this->convertFieldName($field, '_gt'), '>', $value];
            } else if (Str::endsWith($field, '_ge')) {
                $where[] = [$this->convertFieldName($field, '_ge'), '>=', $value];
            } else if (Str::endsWith($field, '_range')) {
                $f = $this->convertFieldName($field, '_range');
                [$beginTime, $endTime] = explode(' - ', $value);
                $where[] = [$f, '>=', $beginTime];
                $where[] = [$f, '<=', $endTime];
            }
        }
        return [$page, $limit, $where];
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
            ->fieldRaw($this->getSearchFields())
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
        $this->assign('row', $this->getAddDefaultValue());
        return $this->fetch($this->getAddPage());
    }

    protected function getAddDefaultValue()
    {
        return [];
    }

    protected function getAddPage()
    {
        return 'edit';
    }

    protected function addOperate()
    {
        $post = $this->request->post();
        $rule = $this->validateAddData($post);
        $this->validate($post, $rule);
        try {
            $save = $this->model->save($post);
        } catch (\Exception $e) {
            $this->error(lang('common.save_fail') . ':' . $e->getMessage());
        }
        $save ? $this->success(lang('common.save_success')) : $this->error(lang('common.save_fail'));
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
            ->fieldRaw($this->getEditFields())
            ->limit(1)->select()->toArray();
        return $list[0];
    }

    protected function getEditFields()
    {
        return $this->getSearchFields();
    }

    protected function editOperate($id, $row)
    {
        $post = $this->request->post();
        $rule = $this->validateEditData($post);
        $this->validate($post, $rule);
        try {
            $save = $row->save($post);
        } catch (\Exception $e) {
            $this->error(lang('common.save_fail'));
        }
        $save ? $this->success(lang('common.save_success')) : $this->error(lang('common.save_fail'));
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
}