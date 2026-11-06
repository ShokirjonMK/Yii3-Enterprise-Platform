<?php

declare(strict_types=1);

/**
 * Form fields generated from the dataset's Field list (shared by Admin and Web forms).
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Modules\MultiDb\Domain\Dataset $d
 * @var array<string,string> $values
 * @var array<string, list<string>> $errors
 */

use App\Modules\MultiDb\Domain\Field;

$v = static fn(string $k): string => isset($values[$k]) && is_scalar($values[$k]) ? (string) $values[$k] : '';
?>
<?php if ($errors !== []): ?>
    <div class="alert alert--error span-2" role="alert">
        <?= $h->t('Please correct the highlighted fields.') ?>
        <?php foreach ($errors['_'] ?? [] as $msg): ?><div><?= $h->e($msg) ?></div><?php endforeach ?>
    </div>
<?php endif ?>
<?php foreach ($d->fields as $f):
    $err = $errors[$f->name] ?? [];
    $id = 'f-' . $f->name;
    $attrs = ' id="' . $h->e($id) . '" name="' . $h->e($f->name) . '"' . ($f->required ? ' required' : '')
        . ($err !== [] ? ' aria-invalid="true" aria-describedby="e-' . $h->e($f->name) . '"' : '');
    $wide = $f->type === Field::TEXT;
    ?>
    <div class="field<?= $err !== [] ? ' field--invalid' : '' ?><?= $wide ? ' span-2' : '' ?>">
        <?php if ($f->type === Field::BOOL): ?>
            <label class="check"><input type="checkbox" value="1"<?= $attrs ?><?= $v($f->name) === '1' ? ' checked' : '' ?>> <?= $h->t($f->label) ?></label>
        <?php else: ?>
            <label for="<?= $h->e($id) ?>"><?= $h->t($f->label) ?><?= $f->required ? ' *' : '' ?></label>
            <?php if ($f->type === Field::ENUM): ?>
                <select<?= $attrs ?>>
                    <option value=""><?= $h->t('— choose —') ?></option>
                    <?php foreach ($f->options as $o): ?>
                        <option value="<?= $h->e($o) ?>"<?= $v($f->name) === $o ? ' selected' : '' ?>><?= $h->e($o) ?></option>
                    <?php endforeach ?>
                </select>
            <?php elseif ($f->type === Field::TEXT): ?>
                <textarea rows="6" maxlength="<?= $f->max ?>"<?= $attrs ?>><?= $h->e($v($f->name)) ?></textarea>
            <?php else:
                [$type, $extra] = match ($f->type) {
                    Field::INT => ['number', ' step="1"' . ($f->min !== null ? ' min="' . (int) $f->min . '"' : '')],
                    Field::DECIMAL => ['text', ' inputmode="decimal" pattern="-?[0-9]+([.,][0-9]{1,2})?"'],
                    Field::DATE => ['date', ''],
                    Field::DATETIME => ['datetime-local', ''],
                    default => ['text', ' maxlength="' . $f->max . '"'],
                };
                ?>
                <input type="<?= $type ?>"<?= $extra ?><?= $attrs ?> value="<?= $h->e($v($f->name)) ?>" autocomplete="off">
            <?php endif ?>
        <?php endif ?>
        <?php if ($f->hint !== ''): ?><div class="field__hint small muted"><?= $h->t($f->hint) ?></div><?php endif ?>
        <?php foreach ($err as $msg): ?><div class="field__error" id="e-<?= $h->e($f->name) ?>"><?= $h->e($msg) ?></div><?php endforeach ?>
    </div>
<?php endforeach ?>
