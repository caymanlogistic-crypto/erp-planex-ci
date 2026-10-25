<?php

function ui_breadcrumbs(string $requestPath, ?string $superadminCompanyName = null, ?array $company = null): array
{
    $role = $_SESSION['role_code'] ?? '';
    $companyName = $_SESSION['company_name'] ?? '';
    $route = parse_route_path($requestPath);

    if ($role === 'superadmin') {
        return superadmin_breadcrumbs($route, $superadminCompanyName);
    }

    return company_breadcrumbs($route, $companyName, $company);
}

function parse_route_path(string $path): array
{
    $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
    $base = app_base_path();
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        $path = substr($path, strlen($base));
    }
    $path = rtrim($path, '/') ?: '/';

    $parts = explode('/', trim($path, '/'));

    // Normalize: remove empty parts
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

    return [
        'path' => $path,
        'parts' => $parts,
    ];
}

function superadmin_breadcrumbs(array $route, ?string $superadminCompanyName = null): array
{
    $crumbs = [['label' => 'SUPERADMIN', 'url' => app_url('/superadmin/companies')]];
    $parts = $route['parts'];

    if (empty($parts)) {
        return $crumbs;
    }

    // /superadmin/companies
    if (count($parts) >= 2 && $parts[0] === 'superadmin' && $parts[1] === 'companies') {
        $crumbs[] = ['label' => 'Компании', 'url' => app_url('/superadmin/companies')];

        if (isset($parts[2]) && ctype_digit($parts[2])) {
            $companyId = (int) $parts[2];
            $companyLabel = $superadminCompanyName ?? get_superadmin_company_name($companyId);
            $crumbs[] = ['label' => $companyLabel, 'url' => app_url('/superadmin/companies/' . $companyId)];

            $action = $parts[3] ?? '';

            if ($action === 'edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } elseif ($action === 'delete') {
                $crumbs[] = ['label' => 'Удаление', 'url' => null];
            } elseif ($action === 'modal-view' || $action === 'modal-edit') {
                // modal - no extra crumb
            } elseif ($action === 'create-owner') {
                $crumbs[] = ['label' => 'Создать руководителя', 'url' => null];
            } elseif ($action === 'owner') {
                $crumbs[] = ['label' => 'Руководитель', 'url' => app_url('/superadmin/companies/' . $companyId . '/owner')];
                if (($parts[4] ?? '') === 'edit') {
                    $crumbs[] = ['label' => 'Редактировать', 'url' => null];
                }
            } elseif ($action === 'users') {
                $crumbs[] = ['label' => 'Пользователи', 'url' => app_url('/superadmin/companies/' . $companyId . '/users')];
                $subAction = $parts[4] ?? '';
                if ($subAction === 'logists' && isset($parts[5]) && ctype_digit($parts[5])) {
                    $crumbs[] = ['label' => 'Просмотр', 'url' => null];
                } elseif ($subAction === 'logists' && ($parts[5] ?? '') === 'create') {
                    $crumbs[] = ['label' => 'Создать', 'url' => null];
                } elseif (isset($parts[5]) && $parts[5] === 'edit' && ctype_digit($parts[4] ?? '')) {
                    // Edit user path: logists/{id}/edit
                } elseif (ctype_digit($parts[4] ?? '')) {
                    $crumbs[] = ['label' => 'Логист', 'url' => null];
                }
            } elseif ($action === 'clients') {
                $crumbs[] = ['label' => 'Клиенты', 'url' => null];
            } elseif ($action === 'contractors') {
                $crumbs[] = ['label' => 'Подрядчики', 'url' => null];
            } elseif ($action === 'drivers') {
                $crumbs[] = ['label' => 'Водители', 'url' => null];
            } elseif ($action === 'vehicles') {
                $crumbs[] = ['label' => 'Транспорт', 'url' => null];
            } elseif ($action === 'crews') {
                $crumbs[] = ['label' => 'Экипажи', 'url' => null];
            } elseif ($action === 'documents') {
                $crumbs[] = ['label' => 'Документы', 'url' => null];
            } elseif ($action === 'access-grants') {
                $crumbs[] = ['label' => 'Доступы', 'url' => null];
            } elseif ($action === 'directories') {
                $crumbs[] = ['label' => 'Каталоги', 'url' => null];
            }
        } elseif (isset($parts[2]) && $parts[2] === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }

        return $crumbs;
    }

    // /superadmin/db-usage
    if (count($parts) >= 2 && $parts[0] === 'superadmin' && $parts[1] === 'db-usage') {
        $crumbs[] = ['label' => 'Использование БД', 'url' => null];
        return $crumbs;
    }

    // /superadmin/deleted-data
    if (count($parts) >= 2 && $parts[0] === 'superadmin' && $parts[1] === 'deleted-data') {
        $crumbs[] = ['label' => 'Удалённые данные', 'url' => app_url('/superadmin/deleted-data')];
        if (isset($parts[2]) && ctype_digit($parts[2])) {
            $crumbs[] = ['label' => 'Запись #' . $parts[2], 'url' => null];
        }
        return $crumbs;
    }

    // /superadmin/dashboard
    if (count($parts) >= 2 && $parts[0] === 'superadmin' && $parts[1] === 'dashboard') {
        $crumbs[] = ['label' => 'Дашборд', 'url' => null];
        return $crumbs;
    }

    return $crumbs;
}

function company_breadcrumbs(array $route, string $companyName, ?array $company = null): array
{
    $realName = (isset($company) && is_array($company) && !empty($company['name']))
        ? $company['name']
        : ($companyName !== '' ? $companyName : 'Компания');
    $label = mb_strtoupper($realName);
    $crumbs = [['label' => $label, 'url' => app_url('/company/dashboard')]];
    $parts = $route['parts'];

    if (count($parts) < 2 || $parts[0] !== 'company') {
        return $crumbs;
    }

    $page = $parts[1] ?? '';

    // /company/dashboard
    if ($page === 'dashboard') {
        $crumbs[] = ['label' => 'Обзор', 'url' => null];
        return $crumbs;
    }

    if ($page === 'statistics' && ($parts[2] ?? '') === 'managers') {
        $crumbs[] = ['label' => 'Статистика', 'url' => null];
        $crumbs[] = ['label' => 'Работа логистов', 'url' => null];
        return $crumbs;
    }

    if ($page === 'statistics' && ($parts[2] ?? '') === 'work') {
        $crumbs[] = ['label' => 'Логисты', 'url' => null];
        $crumbs[] = ['label' => 'Статистика работы', 'url' => null];
        return $crumbs;
    }

    // /company/clients
    if ($page === 'clients') {
        $crumbs[] = ['label' => 'Клиенты', 'url' => app_url('/company/clients')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $crumbs[] = ['label' => 'Карточка', 'url' => null];
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/contractors
    if ($page === 'contractors') {
        $crumbs[] = ['label' => 'Подрядчики', 'url' => app_url('/company/contractors')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $action = $parts[3] ?? '';
            if ($action === 'edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } elseif ($action === 'add-crew') {
                $crumbs[] = ['label' => 'Добавить экипаж', 'url' => null];
            } else {
                $crumbs[] = ['label' => 'Карточка', 'url' => null];
            }
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        } elseif ($id === 'create-full') {
            $crumbs[] = ['label' => 'Создать + Водитель + ТС', 'url' => null];
        }
        return $crumbs;
    }

    // /company/drivers
    if ($page === 'drivers') {
        $crumbs[] = ['label' => 'Подрядчики', 'url' => app_url('/company/contractors')];
        $crumbs[] = ['label' => 'Список водителей', 'url' => app_url('/company/drivers')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $crumbs[] = ['label' => 'Карточка', 'url' => null];
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/vehicle-sets
    if ($page === 'vehicle-sets') {
        $crumbs[] = ['label' => 'Подрядчики', 'url' => app_url('/company/contractors')];
        $crumbs[] = ['label' => 'Список транспорта', 'url' => app_url('/company/vehicle-sets')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $action = $parts[3] ?? '';
            if ($action === 'edit' || $action === 'modal-edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } else {
                $crumbs[] = ['label' => 'Карточка', 'url' => null];
            }
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/route-executors
    if ($page === 'route-executors') {
        $crumbs[] = ['label' => 'Исполнители рейса', 'url' => app_url('/company/route-executors')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $action = $parts[3] ?? '';
            if ($action === 'edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } else {
                $crumbs[] = ['label' => 'Карточка', 'url' => null];
            }
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/logists
    if ($page === 'logists') {
        $crumbs[] = ['label' => 'Логисты', 'url' => app_url('/company/logists')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $action = $parts[3] ?? '';
            if ($action === 'edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } else {
                $crumbs[] = ['label' => 'Карточка', 'url' => null];
            }
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/vehicles
    if ($page === 'vehicles') {
        $crumbs[] = ['label' => 'Транспортные единицы', 'url' => app_url('/company/vehicles')];
        $id = $parts[2] ?? '';
        if (ctype_digit($id)) {
            $action = $parts[3] ?? '';
            if ($action === 'edit') {
                $crumbs[] = ['label' => 'Редактировать', 'url' => null];
            } else {
                $crumbs[] = ['label' => 'Карточка', 'url' => null];
            }
        } elseif ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        }
        return $crumbs;
    }

    // /company/trips
    if ($page === 'trips') {
        $sub = $parts[2] ?? '';
        if ($sub === 'linear') {
            $crumbs[] = ['label' => 'Рейсы', 'url' => null];
            $crumbs[] = ['label' => 'Линейные', 'url' => app_url('/company/trips/linear')];
        } elseif ($sub === 'departures') {
            $crumbs[] = ['label' => 'Рейсы', 'url' => null];
            $crumbs[] = ['label' => 'Отходы', 'url' => null];
        }
        return $crumbs;
    }

    // /company/feo/requests
    if ($page === 'feo' && ($parts[2] ?? '') === 'requests') {
        $crumbs[] = ['label' => 'Заявки ФЭО', 'url' => null];
        $crumbs[] = ['label' => 'Все заявки', 'url' => app_url('/company/feo/requests')];
        return $crumbs;
    }

    // /company/finance/received-paid
    if ($page === 'finance' && ($parts[2] ?? '') === 'received-paid') {
        $crumbs[] = ['label' => 'Финансы', 'url' => null];
        $crumbs[] = ['label' => 'Получено/оплачено', 'url' => null];
        return $crumbs;
    }

    // /company/finance/bank-accounts and its nested matching-rules workflow
    if ($page === 'finance' && ($parts[2] ?? '') === 'bank-accounts') {
        $crumbs[] = ['label' => 'Финансы', 'url' => null];
        $crumbs[] = ['label' => 'Выписки со счёта', 'url' => null];
        return $crumbs;
    }
    if ($page === 'finance' && ($parts[2] ?? '') === 'settings' && ($parts[3] ?? '') === 'matching-rules') {
        $crumbs[] = ['label' => 'Финансы', 'url' => null];
        $crumbs[] = ['label' => 'Выписки со счёта', 'url' => app_url('/company/finance/bank-accounts')];
        $crumbs[] = ['label' => 'Правила разнесения', 'url' => null];
        return $crumbs;
    }

    // /company/bank-statement-settings
    if ($page === 'bank-statement-settings') {
        $crumbs[] = ['label' => 'Настройки выписок', 'url' => null];
        return $crumbs;
    }

    // /company/responsible-assignments
    if ($page === 'responsible-assignments') {
        $crumbs[] = ['label' => 'Ответственные логисты', 'url' => null];
        return $crumbs;
    }

    // /company/contractor-assignments
    if ($page === 'contractor-assignments') {
        $crumbs[] = ['label' => 'Привязка перевозчиков', 'url' => null];
        return $crumbs;
    }

    // /company/documents
    if ($page === 'documents') {
        $crumbs[] = ['label' => 'Документы', 'url' => app_url('/company/documents')];
        if (($parts[2] ?? '') === 'upload') {
            $crumbs[] = ['label' => 'Загрузить', 'url' => null];
        }
        return $crumbs;
    }

    // /company/document-types
    if ($page === 'document-types') {
        $crumbs[] = ['label' => 'Типы документов', 'url' => app_url('/company/document-types')];
        $id = $parts[2] ?? '';
        if ($id === 'create') {
            $crumbs[] = ['label' => 'Создать', 'url' => null];
        } elseif (ctype_digit($id) && ($parts[3] ?? '') === 'edit') {
            $crumbs[] = ['label' => 'Редактировать', 'url' => null];
        }
        return $crumbs;
    }

    return $crumbs;
}

function get_superadmin_company_name(int $companyId): string
{
    return 'Компания #' . $companyId;
}
