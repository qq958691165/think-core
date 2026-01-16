<?php
namespace Qscmf\Builder\FormType\Num;

use AntdAdmin\Component\ColumnType\BaseColumn;
use Qscmf\Builder\FormType\FormType;
use Quansitech\BuilderAdapterForAntdAdmin\BuilderAdapter\FormAdapter\IAntdFormColumn;
use Think\View;

class Num implements FormType, IAntdFormColumn
{

    public function build(array $form_type){
        $view = new View();
        $view->assign('form', $form_type);
        
        if($form_type['item_option']['read_only']){
            $content = $view->fetch(__DIR__ . '/num_read_only.html');
        }
        else{
            $content = $view->fetch(__DIR__ . '/num.html');
        }
        return $content;
    }

    public function formColumnAntdRender($options): BaseColumn
    {
        $column = new \AntdAdmin\Component\ColumnType\Text($options['name'], $options['title']);

        return $column;
    }
}