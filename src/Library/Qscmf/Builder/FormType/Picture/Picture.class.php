<?php
namespace Qscmf\Builder\FormType\Picture;

use AntdAdmin\Component\ColumnType\BaseColumn;
use AntdAdmin\Component\ColumnType\Image;
use Illuminate\Support\Str;
use Qscmf\Builder\FormType\FormType;
use Qscmf\Builder\FormType\TUploadConfig;
use Quansitech\BuilderAdapterForAntdAdmin\BuilderAdapter\FormAdapter\IAntdFormColumn;
use Think\View;

class Picture implements FormType, IAntdFormColumn
{
    use TUploadConfig;

    public function build(array $form_type){
        $upload_type = $this->genUploadConfigCls($form_type['extra_attr'], 'image');
        $view = new View();
        $view->assign('form', $form_type);
        $view->assign('gid', Str::uuid());
        $view->assign('file_ext',  $upload_type->getExts());
        $view->assign('file_max_size',  $upload_type->getMaxSize());
        $view->assign('cate', $upload_type->getType());
        $view->assign('cacl_file_hash', $form_type["options"]['cacl_file_hash']??1);
        if($form_type['item_option']['read_only']){
            $content = $view->fetch(__DIR__ . '/picture_read_only.html');
        }
        else{
            $content = $view->fetch(__DIR__ . '/picture.html');
        }

        return $content;
    }

    public function formColumnAntdRender($options): BaseColumn
    {
        $column = new Image($options['name'], $options['title']);

        return $column;
    }
}