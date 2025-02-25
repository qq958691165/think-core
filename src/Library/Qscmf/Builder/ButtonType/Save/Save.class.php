<?php
namespace Qscmf\Builder\ButtonType\Save;

use AntdAdmin\Component\Table\ActionType\BaseAction;
use AntdAdmin\Component\Table\ActionType\StartEditable;
use Qscmf\Builder\ButtonType\ButtonType;
use Qscmf\Builder\ListBuilder;
use Quansitech\BuilderAdapterForAntdAdmin\BuilderAdapter\ListAdapter\IAntdTableButton;

class Save extends ButtonType implements IAntdTableButton
{

    public function build(array &$option, ListBuilder $listBuilder){
        $my_attribute['title'] = '保存';
        $my_attribute['target-form'] = $listBuilder?->getGid();
        $my_attribute['class'] = 'btn btn-primary ajax-post confirm';
        $my_attribute['href']  = U(
            '/' . MODULE_NAME.'/'.CONTROLLER_NAME.'/save'
        );

        $option['attribute'] = array_merge($my_attribute, is_array($option['attribute']) ? $option['attribute'] : [] );

        return '';
    }

    public function tableButtonAntdRender($options, $listBuilder): BaseAction
    {
        $data = [
            $listBuilder->table_data_list_key => '__' . $listBuilder->table_data_list_key . '__',
        ];

        foreach ($listBuilder->table_column_list as $column) {
            if (!$column['editable']) {
                continue;
            }
            $data[$column['name']] = '__' . $column['name'] . '__';
        }

        $btn = new StartEditable('编辑');
        $btn->saveRequest('post', U('save'), $data);
        return $btn;
    }
}