<?php

if (!function_exists('handleCompanyDriverCreate')) {
    function handleCompanyDriverCreate(array $config, $db, string $renderMode = 'page'): void
    {
        $pageTitle = 'Создать водителя';
        $pageContext = 'Водители › Компания';

        $companyId = (int)(getSessionCompanyId() ?? 0);
        $errors = [];
        $old = $_POST;
        $formError = null;
        $success = false;
        $createdDriver = null;
        $docErrors = [];
        $uploadedDocs = [];
        $docTypes = [];

        $renderResponse = static function () use (
            $renderMode,
            $pageTitle,
            $pageContext,
            &$company,
            &$success,
            &$errors,
            &$old,
            &$formError,
            &$createdDriver,
            &$docErrors,
            &$uploadedDocs,
            &$docTypes,
            &$content,
            &$topbarCrumbs
        ): void {
            if ($renderMode === 'modal') {
                header('Content-Type: text/html; charset=utf-8');
                if ($success) {
                    echo '<div data-driver-create-success="1"></div>';
                    return;
                }

                $driverCreateFormMode = 'modal';
                $driverFormAction = '/company/drivers/modal-create';
                require base_path('app/View/partials/company_driver_create_form.php');
                return;
            }

            ob_start();
            require base_path('app/View/pages/company_drivers_create.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
        };

        if ($companyId <= 0) {
            $company = null;
            $formError = 'Компания не найдена';
            $renderResponse();
            return;
        }

        try {
            $pdo = $db->connection();
            $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$company) {
                $company = null;
                $formError = 'Компания не найдена';
                $renderResponse();
                return;
            }

            //$topbarCrumbs = [
            //    ['label' => mb_strtoupper($company['name']), 'url' => '/company/dashboard'],
            //    ['label' => 'Подрядчики', 'url' => null],
            //    ['label' => 'Водители', 'url' => '/company/drivers'],
            //    ['label' => 'Создать водителя', 'url' => null],
            //];

            if ($company['status'] !== 'active') {
                $formError = 'Создание водителей недоступно';
                $renderResponse();
                return;
            }

            $localDbConfig = companyDatabaseConfig($config, $company);
            $localDb = new \App\Core\Database($localDbConfig);
            $localPdo = $localDb->connection();

            // Schema changes belong to numbered local migrations, never to an HTTP request.
            // Check every dependency before inserting a driver so a missing table or column
            // cannot leave a partially created entity behind.
            $localPdo->query('SELECT id, full_name, created_by_user_id, created_by_role FROM drivers LIMIT 0');
            $localPdo->query('SELECT id, name, code, entity_type, sort_order FROM document_types LIMIT 0');
            $localPdo->query('SELECT driver_id, phone, is_main, comment, created_by_user_id, created_by_role FROM driver_phones LIMIT 0');
            $localPdo->query('SELECT id, document_type_id, created_by_user_id, created_by_role FROM documents LIMIT 0');

            $docTypes = $localPdo->query("SELECT id, name, code, entity_type FROM document_types WHERE entity_type = 'driver' OR entity_type IS NULL ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

            $normalizeSpaces = static function ($value): string {
                return preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '';
            };
            $normalizeDateInput = static function ($value) use ($normalizeSpaces): ?string {
                $value = $normalizeSpaces($value);
                if ($value === '') {
                    return null;
                }

                $value = preg_replace('/\s*(г(?:\.|ода?|од)?)\s*$/ui', '', $value) ?? $value;
                $value = $normalizeSpaces($value);

                if (preg_match('/^(\d{8})$/', $value, $m)) {
                    $day = (int)substr($m[1], 0, 2);
                    $month = (int)substr($m[1], 2, 2);
                    $year = (int)substr($m[1], 4, 4);
                } elseif (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $value, $m)) {
                    $year = (int)$m[1];
                    $month = (int)$m[2];
                    $day = (int)$m[3];
                } elseif (preg_match('/^(\d{1,2})[\s.\/-](\d{1,2})[\s.\/-](\d{2}|\d{4})$/', $value, $m)) {
                    $day = (int)$m[1];
                    $month = (int)$m[2];
                    $year = (int)$m[3];
                    if ($year < 100) {
                        $year = $year <= 49 ? 2000 + $year : 1900 + $year;
                    }
                } else {
                    return false;
                }

                if (!checkdate($month, $day, $year)) {
                    return false;
                }

                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            };
            $normalizePhone = static function ($value): ?string {
                $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
                if (strlen($digits) === 11 && $digits[0] === '8') {
                    $digits = '7' . substr($digits, 1);
                } elseif (strlen($digits) === 10) {
                    $digits = '7' . $digits;
                }
                if (strlen($digits) !== 11 || $digits[0] !== '7') {
                    return null;
                }

                return sprintf(
                    '+7 %s %s-%s-%s',
                    substr($digits, 1, 3),
                    substr($digits, 4, 3),
                    substr($digits, 7, 2),
                    substr($digits, 9, 2)
                );
            };
            $normalizeTenDigits = static function ($value): ?string {
                $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
                if (strlen($digits) !== 10) {
                    return null;
                }

                return substr($digits, 0, 4) . ' ' . substr($digits, 4, 6);
            };
            $normalizeDepartmentCode = static function ($value): ?string {
                $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
                if (strlen($digits) !== 6) {
                    return null;
                }

                return substr($digits, 0, 3) . '-' . substr($digits, 3, 3);
            };
            $normalizeSnils = static function ($value): ?string {
                $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
                if (strlen($digits) !== 11) {
                    return null;
                }

                return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3) . ' ' . substr($digits, 9, 2);
            };
            $normalizeFullName = static function ($value) use ($normalizeSpaces): ?string {
                $value = $normalizeSpaces($value);
                if ($value === '') {
                    return null;
                }
                $parts = preg_split('/\s+/u', $value) ?: [];
                if (count($parts) !== 3) {
                    return false;
                }

                $parts = array_map(static function ($part) {
                    $part = mb_strtolower($part, 'UTF-8');
                    return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($part, 1, null, 'UTF-8');
                }, $parts);

                return implode(' ', $parts);
            };

            if (isPostTruncated()) {
                $formError = 'Общий размер отправки превышает серверный лимит. Для ERP требуется настройка post_max_size не менее 100M. Уменьшите количество файлов или обратитесь к администратору.';
                $renderResponse();
                return;
            }

            $fullName = $normalizeFullName($_POST['full_name'] ?? '');
            $phoneRaw = trim((string)($_POST['phone'] ?? ''));
            $phone = $normalizePhone($phoneRaw);
            $licenseNumberRaw = trim((string)($_POST['license_number'] ?? ''));
            $licenseNumber = $licenseNumberRaw !== '' ? $normalizeTenDigits($licenseNumberRaw) : null;
            $licenseCategory = trim((string)($_POST['license_category'] ?? ''));
            $licenseIssueDateRaw = trim((string)($_POST['license_issue_date'] ?? ''));
            $licenseIssueDate = $licenseIssueDateRaw !== '' ? $normalizeDateInput($licenseIssueDateRaw) : null;
            $licenseExpireDate = trim((string)($_POST['license_expire_date'] ?? ''));
            $comments = $normalizeSpaces($_POST['comments'] ?? '');
            $passportNumberRaw = trim((string)($_POST['passport_number'] ?? ''));
            $passportNumber = $passportNumberRaw !== '' ? $normalizeTenDigits($passportNumberRaw) : null;
            $passportIssuedBy = $normalizeSpaces($_POST['passport_issued_by'] ?? '');
            $passportDepartmentCodeRaw = trim((string)($_POST['passport_department_code'] ?? ''));
            $passportDepartmentCode = $passportDepartmentCodeRaw !== '' ? $normalizeDepartmentCode($passportDepartmentCodeRaw) : null;
            $passportIssueDateRaw = trim((string)($_POST['passport_issue_date'] ?? ''));
            $passportIssueDate = $passportIssueDateRaw !== '' ? $normalizeDateInput($passportIssueDateRaw) : null;
            $snilsRaw = trim((string)($_POST['snils'] ?? ''));
            $snils = $snilsRaw !== '' ? $normalizeSnils($snilsRaw) : null;
            $email = strtolower(trim((string)($_POST['email'] ?? '')));

            $old['full_name'] = is_string($fullName) ? $fullName : trim((string)($_POST['full_name'] ?? ''));
            $old['phone'] = $phone ?? $phoneRaw;
            $old['license_number'] = $licenseNumber ?? $licenseNumberRaw;
            $old['license_issue_date'] = is_string($licenseIssueDate) ? date('d.m.Y', strtotime($licenseIssueDate)) : $licenseIssueDateRaw;
            $old['comments'] = $comments;
            $old['passport_number'] = $passportNumber ?? $passportNumberRaw;
            $old['passport_issued_by'] = $passportIssuedBy;
            $old['passport_department_code'] = $passportDepartmentCode ?? $passportDepartmentCodeRaw;
            $old['passport_issue_date'] = is_string($passportIssueDate) ? date('d.m.Y', strtotime($passportIssueDate)) : $passportIssueDateRaw;
            $old['snils'] = $snils ?? $snilsRaw;
            $old['email'] = $email;

            if ($fullName === null || $fullName === false) {
                $errors['full_name'] = 'Укажите ФИО полностью: фамилия, имя и отчество';
            }
            if ($phoneRaw !== '' && $phone === null) {
                $errors['phone'] = 'Телефон должен содержать 10 или 11 цифр, например +7 900 000-00-00';
            }
            if ($licenseNumberRaw !== '' && $licenseNumber === null) {
                $errors['license_number'] = 'Номер водительского удостоверения должен содержать 10 цифр';
            }
            if ($licenseIssueDateRaw !== '' && $licenseIssueDate === false) {
                $errors['license_issue_date'] = 'Укажите корректную дату выдачи ВУ в формате ДД.ММ.ГГГГ';
            }
            if ($passportNumberRaw !== '' && $passportNumber === null) {
                $errors['passport_number'] = 'Серия и номер паспорта должны содержать 10 цифр';
            }
            if ($passportDepartmentCodeRaw !== '' && $passportDepartmentCode === null) {
                $errors['passport_department_code'] = 'Код подразделения должен состоять из 6 цифр в формате 000-000';
            }
            if ($passportIssueDateRaw !== '' && $passportIssueDate === false) {
                $errors['passport_issue_date'] = 'Укажите корректную дату выдачи паспорта в формате ДД.ММ.ГГГГ';
            }
            if ($snilsRaw !== '' && $snils === null) {
                $errors['snils'] = 'СНИЛС должен содержать 11 цифр';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Укажите корректный email, например driver@example.ru';
            }

            if (!empty($errors)) {
                $renderResponse();
                return;
            }

            $totalSizeError = validateTotalUploadSize();
            if ($totalSizeError !== '') {
                $formError = $totalSizeError;
                $renderResponse();
                return;
            }

            $insert = $localPdo->prepare(
                'INSERT INTO drivers (full_name, phone, email, license_number, license_category,
                 license_issue_date, license_expire_date, passport_number, passport_issued_by,
                 passport_department_code, passport_issue_date, snils, status, comments, created_by_user_id, created_by_role)
                 VALUES (:full_name, :phone, :email, :license_number, :license_category,
                 :license_issue_date, :license_expire_date, :passport_number, :passport_issued_by,
                 :passport_department_code, :passport_issue_date, :snils, :status, :comments, :created_by_user_id, :created_by_role)'
            );
            $insert->execute([
                ':full_name' => $fullName,
                ':phone' => $phone ?? '',
                ':email' => $email !== '' ? $email : null,
                ':license_number' => $licenseNumber,
                ':license_category' => $licenseCategory !== '' ? $licenseCategory : null,
                ':license_issue_date' => $licenseIssueDate ?: null,
                ':license_expire_date' => $licenseExpireDate !== '' ? $licenseExpireDate : null,
                ':passport_number' => $passportNumber,
                ':passport_issued_by' => $passportIssuedBy !== '' ? $passportIssuedBy : null,
                ':passport_department_code' => $passportDepartmentCode,
                ':passport_issue_date' => $passportIssueDate ?: null,
                ':snils' => $snils,
                ':status' => 'active',
                ':comments' => $comments !== '' ? $comments : null,
                ':created_by_user_id' => (int)$_SESSION['user_id'],
                ':created_by_role' => $_SESSION['role_code'],
            ]);

            $createdDriver = [
                'id' => $localPdo->lastInsertId(),
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'passport_number' => $passportNumber,
                'passport_issued_by' => $passportIssuedBy !== '' ? $passportIssuedBy : null,
                'passport_issue_date' => $passportIssueDate ? date('d.m.Y', strtotime($passportIssueDate)) : null,
                'snils' => $snils,
                'license_number' => $licenseNumber,
                'license_category' => $licenseCategory !== '' ? $licenseCategory : null,
            ];
            $newDriverId = (int)$localPdo->lastInsertId();
            $entityType = 'driver';

            $extraPhones = $_POST['extra_phones'] ?? [];
            if (is_array($extraPhones)) {
                $phoneComments = $_POST['extra_phone_comments'] ?? [];
                $phoneIns = $localPdo->prepare('INSERT INTO driver_phones (driver_id, phone, is_main, comment, created_by_user_id, created_by_role) VALUES (:did, :phone, 0, :comment, :uid, :role)');
                foreach ($extraPhones as $pIdx => $extraPhone) {
                    $extraPhone = $normalizePhone($extraPhone ?? '');
                    if ($extraPhone === '' || $extraPhone === null) {
                        continue;
                    }
                    $phoneIns->execute([
                        ':did' => $newDriverId,
                        ':phone' => $extraPhone,
                        ':comment' => isset($phoneComments[$pIdx]) ? $normalizeSpaces($phoneComments[$pIdx]) : null,
                        ':uid' => (int)$_SESSION['user_id'],
                        ':role' => $_SESSION['role_code'],
                    ]);
                }
                $extraPhonesSaved = $localPdo->prepare("SELECT phone, comment FROM driver_phones WHERE driver_id = ? AND is_main = 0 ORDER BY id ASC");
                $extraPhonesSaved->execute([$newDriverId]);
                $createdDriver['extra_phones'] = $extraPhonesSaved->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $createdDriver['extra_phones'] = [];
            }

            $allowedExt = ['pdf', 'doc', 'docx', 'rtf', 'odt', 'xls', 'xlsx', 'csv', 'ods', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'txt'];
            $maxSize = 20 * 1024 * 1024;

            if (!empty($_FILES['predef_doc']['name']) && is_array($_FILES['predef_doc']['name'])) {
                foreach ($_FILES['predef_doc']['name'] as $code => $nameValue) {
                    $docTypeName = $_POST['predef_doc_type'][$code] ?? '';
                    if (is_array($nameValue)) {
                        $names = $nameValue;
                        $tmpNames = $_FILES['predef_doc']['tmp_name'][$code] ?? [];
                        $uploadErrors = $_FILES['predef_doc']['error'][$code] ?? [];
                        $sizes = $_FILES['predef_doc']['size'][$code] ?? [];
                        $types = $_FILES['predef_doc']['type'][$code] ?? [];
                    } else {
                        $names = [$nameValue];
                        $tmpNames = [$_FILES['predef_doc']['tmp_name'][$code] ?? ''];
                        $uploadErrors = [$_FILES['predef_doc']['error'][$code] ?? UPLOAD_ERR_NO_FILE];
                        $sizes = [$_FILES['predef_doc']['size'][$code] ?? 0];
                        $types = [$_FILES['predef_doc']['type'][$code] ?? ''];
                    }

                    foreach ($names as $fileIdx => $origName) {
                        $fe = $uploadErrors[$fileIdx] ?? UPLOAD_ERR_NO_FILE;
                        if ($fe !== UPLOAD_ERR_OK || trim((string)$origName) === '') {
                            continue;
                        }
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        $fs = $sizes[$fileIdx] ?? 0;
                        if (!in_array($ext, $allowedExt, true)) {
                            $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: недопустимый формат';
                            continue;
                        }
                        if ($fs > $maxSize) {
                            $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: размер > 20 МБ';
                            continue;
                        }
                        if (strpos($origName, '../') !== false || strpos($origName, '..\\') !== false || strpos($origName, '/') !== false || strpos($origName, '\\') !== false) {
                            $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: недопустимое имя';
                            continue;
                        }

                        try {
                            $storedName = uniqid('doc_', true) . '.' . $ext;
                            $relativeDir = 'companies/' . $companyId . '/documents/' . $entityType . '/' . $newDriverId;
                            $absoluteDir = storage_path($relativeDir);
                            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) {
                                $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: не удалось создать директорию';
                                continue;
                            }
                            $destPath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
                            $tmpSrc = $tmpNames[$fileIdx] ?? '';
                            if ($tmpSrc === '' || !move_uploaded_file($tmpSrc, $destPath)) {
                                $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: не удалось сохранить';
                                continue;
                            }
                            $mime = $types[$fileIdx] ?? '';
                            $dtId = null;
                            if ($docTypeName !== '') {
                                $dts = $localPdo->prepare("SELECT id FROM document_types WHERE name = ? AND entity_type = ? LIMIT 1");
                                $dts->execute([$docTypeName, $entityType]);
                                $dtId = $dts->fetchColumn() ?: null;
                            }
                            $ins = $localPdo->prepare('INSERT INTO documents (entity_type, entity_id, document_type, document_type_id, original_name, stored_name, relative_path, mime_type, file_size, status, uploaded_by_user_id, uploaded_by_role, created_by_user_id, created_by_role) VALUES (:et, :eid, :dtype, :dtid, :oname, :sname, :rpath, :mime, :fsize, :status, :uid, :role, :cuid, :crole)');
                            $ins->execute([
                                ':et' => $entityType,
                                ':eid' => $newDriverId,
                                ':dtype' => $docTypeName ?: null,
                                ':dtid' => $dtId,
                                ':oname' => $origName,
                                ':sname' => $storedName,
                                ':rpath' => $relativeDir . '/' . $storedName,
                                ':mime' => $mime,
                                ':fsize' => $fs,
                                ':status' => 'uploaded',
                                ':uid' => (int)$_SESSION['user_id'],
                                ':role' => $_SESSION['role_code'],
                                ':cuid' => (int)$_SESSION['user_id'],
                                ':crole' => $_SESSION['role_code'],
                            ]);
                            $uploadedDocs[] = $docTypeName . ' (' . $origName . ')';
                        } catch (\Exception $ex) {
                            $docErrors[] = 'Предопределённый документ «' . $docTypeName . '»: ошибка сохранения (' . $ex->getMessage() . ')';
                        }
                    }
                }
            }

            if (!empty($_FILES['custom_doc_file']['name']) && is_array($_FILES['custom_doc_file']['name'])) {
                foreach ($_FILES['custom_doc_file']['name'] as $idx => $origName) {
                    $fe = $_FILES['custom_doc_file']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
                    if ($fe !== UPLOAD_ERR_OK || trim((string)$origName) === '') {
                        continue;
                    }
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $fs = $_FILES['custom_doc_file']['size'][$idx];
                    if (!in_array($ext, $allowedExt, true)) {
                        $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': недопустимый формат';
                        continue;
                    }
                    if ($fs > $maxSize) {
                        $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': размер > 20 МБ';
                        continue;
                    }
                    if (strpos($origName, '../') !== false || strpos($origName, '..\\') !== false || strpos($origName, '/') !== false || strpos($origName, '\\') !== false) {
                        $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': недопустимое имя';
                        continue;
                    }
                    $customType = $normalizeSpaces($_POST['custom_doc_type'][$idx] ?? '');
                    if ($customType === '') {
                        $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': укажите тип документа';
                        continue;
                    }

                    $first = mb_substr($customType, 0, 1, 'UTF-8');
                    $rest = mb_substr($customType, 1, null, 'UTF-8');
                    $docTypeName = mb_strtoupper($first, 'UTF-8') . mb_strtolower($rest, 'UTF-8');
                    $dtId = null;

                    try {
                        $idts = $localPdo->prepare("INSERT IGNORE INTO document_types (name, entity_type, category, created_by_user_id, created_by_role) VALUES (:name, :et, 'custom', :uid, :role)");
                        $idts->execute([
                            ':name' => $docTypeName,
                            ':et' => $entityType,
                            ':uid' => (int)$_SESSION['user_id'],
                            ':role' => $_SESSION['role_code'],
                        ]);
                        $dtId = $localPdo->lastInsertId();
                        if (!$dtId) {
                            $g = $localPdo->prepare("SELECT id FROM document_types WHERE name = ? AND entity_type = ? LIMIT 1");
                            $g->execute([$docTypeName, $entityType]);
                            $dtId = $g->fetchColumn() ?: null;
                        }
                    } catch (\Exception $ex) {
                    }

                    try {
                        $storedName = uniqid('doc_', true) . '.' . $ext;
                        $relativeDir = 'companies/' . $companyId . '/documents/' . $entityType . '/' . $newDriverId;
                        $absoluteDir = storage_path($relativeDir);
                        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) {
                            $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': не удалось создать директорию';
                            continue;
                        }
                        $destPath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
                        if (!move_uploaded_file($_FILES['custom_doc_file']['tmp_name'][$idx], $destPath)) {
                            $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': не удалось сохранить';
                            continue;
                        }
                        $mime = $_FILES['custom_doc_file']['type'][$idx];
                        $ins = $localPdo->prepare('INSERT INTO documents (entity_type, entity_id, document_type, document_type_id, original_name, stored_name, relative_path, mime_type, file_size, status, uploaded_by_user_id, uploaded_by_role, created_by_user_id, created_by_role) VALUES (:et, :eid, :dtype, :dtid, :oname, :sname, :rpath, :mime, :fsize, :status, :uid, :role, :cuid, :crole)');
                        $ins->execute([
                            ':et' => $entityType,
                            ':eid' => $newDriverId,
                            ':dtype' => $docTypeName ?: null,
                            ':dtid' => $dtId,
                            ':oname' => $origName,
                            ':sname' => $storedName,
                            ':rpath' => $relativeDir . '/' . $storedName,
                            ':mime' => $mime,
                            ':fsize' => $fs,
                            ':status' => 'uploaded',
                            ':uid' => (int)$_SESSION['user_id'],
                            ':role' => $_SESSION['role_code'],
                            ':cuid' => (int)$_SESSION['user_id'],
                            ':crole' => $_SESSION['role_code'],
                        ]);
                        $uploadedDocs[] = $docTypeName . ' (' . $origName . ')';
                    } catch (\Exception $ex) {
                        $docErrors[] = 'Произвольный документ #' . ($idx + 1) . ': ошибка сохранения (' . $ex->getMessage() . ')';
                    }
                }
            }

            $success = true;
        } catch (\PDOException $e) {
            $company = $company ?? null;
            $formError = 'Не удалось сохранить водителя из-за технической ошибки. Если рядом с полями нет подсказки, ошибка не связана с заполнением формы — сообщите администратору.';
        } catch (\Exception $e) {
            $company = $company ?? null;
            $formError = 'Не удалось сохранить водителя из-за технической ошибки. Если рядом с полями нет подсказки, ошибка не связана с заполнением формы — сообщите администратору.';
        }

        $renderResponse();
    }
}
