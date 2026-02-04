<?php

namespace themes\cargo\inc\libs\PhpSpreadsheet\Cell;

use themes\cargo\inc\libs\PhpSpreadsheet\Cell\Cell;

interface IValueBinder
{
    /**
     * Bind value to a cell.
     *
     * @param Cell $cell Cell to bind value to
     * @param mixed $value Value to bind in cell
     */
    public function bindValue(Cell $cell, mixed $value): bool;
}
