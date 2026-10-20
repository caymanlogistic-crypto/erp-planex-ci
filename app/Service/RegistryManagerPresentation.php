<?php
namespace App\Service;

require_once __DIR__.'/WasteTripRegistryPresenter.php';

/** Scoped manager cells/search only; FEO and the original formatter stay unchanged. */
final class RegistryManagerPresentation
{
    /** Sources use «Фамилия Имя [Отчество]» or an already rendered «Имя Ф.». */
    public static function name(string $name): string
    {
        $normalized=trim(preg_replace('/\s+/u',' ',$name)??$name);
        if(preg_match('/^\S+ \p{L}\.$/u',$normalized))return $normalized;
        return WasteTripRegistryPresenter::manager($normalized);
    }
}
