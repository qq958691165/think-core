<?php

namespace Qscmf\Builder\Antd\BuilderAdapter\FormAdapter;

use AntdAdmin\Component\Form\ActionType\BaseAction;

interface IAntdFormButton
{
    public function formButtonAntdRender($options): BaseAction;
}