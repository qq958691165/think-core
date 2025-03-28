<?php
namespace Qscmf\Builder\ColumnType\Pictures;

use AntdAdmin\Component\ColumnType\BaseColumn;
use AntdAdmin\Component\ColumnType\Image;
use Qscmf\Builder\ColumnType\ColumnType;
use Quansitech\BuilderAdapterForAntdAdmin\BuilderAdapter\ListAdapter\IAntdTableColumn;
use Think\View;

class Pictures extends ColumnType implements IAntdTableColumn
{
    public function build(array &$option, array $data, $listBuilder)
    {
        $pic = explode(',', $data[$option['name']]);
        $images = [];
        foreach($pic as $v){
            if(!$v){
                continue;
            }
            $url = showFileUrl($v);
            switch ($option['value']){
                case 'oss':
                    $small_url = combineOssUrlImgOpt($url,'x-oss-process=image/resize,m_fill,w_40,h_40');
                    break;
                case 'imageproxy':
                    $small_url = \Qscmf\Utils\Libs\Common::imageproxy('40x40', $v);
                    break;
                default:
                    $small_url = $url;
                    break;
            }
            $images[] = [
                'url' => $url,
                'small_url' => $small_url,
            ];
        }

        $view = new View();
        $view->assign('images', $images);
        $content = $view->fetch(__DIR__ . '/pictures.html');
        return $content;
    }

    public function tableColumnAntdRender($options, &$datalist, $listBuilder): BaseColumn
    {
        $col = new Image($options['name'], $options['title']);
        $col->setMaxCount(99);
        return $col;
    }
}