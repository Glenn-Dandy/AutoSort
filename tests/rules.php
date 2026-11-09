<?php

namespace OCA\AutoSort\AppInfo {
    class Application {
        public const APP_ID = 'autosort';
    }
}

namespace OCA\AutoSort\Service {
    class PdfTextExtractor {
        public string $text = '';

        public function extract($file): string {
            return $this->text;
        }
    }

    class SettingsService {
        public string $unmatched = '';

        public function getUnmatched(string $userId): string {
            return $this->unmatched;
        }
    }

    class TagService {
    }
}

namespace OCA\AutoSort\Db {
    use OCP\AppFramework\Db\DoesNotExistException;

    class LogMapper {
    }

    class RuleMapper {
        public array $rules = [];

        public function findAllForUser(string $userId): array {
            return array_values(array_filter($this->rules, static fn ($rule) => $rule->getUserId() === $userId));
        }

        public function findForUser(int $id, string $userId) {
            foreach ($this->findAllForUser($userId) as $rule) {
                if ($rule->getId() === $id) {
                    return $rule;
                }
            }
            throw new DoesNotExistException();
        }

        public function insert($rule) {
            $this->rules[] = $rule;
            return $rule;
        }

        public function update($rule) {
            return $rule;
        }

        public function delete($rule) {
            return $rule;
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Db/Rule.php';
    require __DIR__ . '/../lib/Service/RuleMatcher.php';
    require __DIR__ . '/../lib/Service/Sorter.php';
    require __DIR__ . '/../lib/Controller/RuleController.php';

    use OCA\AutoSort\Controller\RuleController;
    use OCA\AutoSort\Db\LogMapper;
    use OCA\AutoSort\Db\Rule;
    use OCA\AutoSort\Db\RuleMapper;
    use OCA\AutoSort\Service\PdfTextExtractor;
    use OCA\AutoSort\Service\SettingsService;
    use OCA\AutoSort\Service\Sorter;
    use OCA\AutoSort\Service\TagService;

    $root = new class implements OCP\Files\IRootFolder {};
    $mapper = new RuleMapper();
    $settings = new SettingsService();
    $extractor = new PdfTextExtractor();
    $sorter = new Sorter($root, $mapper, $settings, $extractor, new TagService(), new LogMapper(), new class implements OCP\AppFramework\Utility\ITimeFactory {}, new class implements Psr\Log\LoggerInterface {});
    $controller = new RuleController(new class implements OCP\IRequest {}, $mapper, new LogMapper(), $settings, new TagService(), $sorter, $extractor, $root, 'anna');
    $file = new class implements OCP\Files\File {
        public function getName(): string {
            return 'scan.pdf';
        }
    };

    function rule(array $values): Rule {
        static $id = 0;
        $values += ['user' => 'anna', 'terms' => [], 'any' => [], 'target' => 'Ziel', 'name' => '', 'after' => '', 'category' => '', 'subcategory' => '', 'enabled' => true];
        $rule = new Rule();
        $rule->setId(++$id);
        $rule->setUserId($values['user']);
        $rule->setTerms(json_encode($values['terms']));
        $rule->setTermsAny(json_encode($values['any']));
        $rule->setTarget($values['target']);
        $rule->setFilename($values['name']);
        $rule->setDateAfter($values['after']);
        $rule->setCategory($values['category']);
        $rule->setSubcategory($values['subcategory']);
        $rule->setEnabled($values['enabled']);
        return $rule;
    }

    function evaluate(string $text, array $rules, string $unmatched = ''): array {
        global $sorter, $mapper, $settings, $extractor, $file;
        $extractor->text = $text;
        $settings->unmatched = $unmatched;
        $mapper->rules = array_map('rule', $rules);
        return $sorter->evaluate('anna', $file);
    }

    $letter = 'Rechnung Nr. 123 vom 14.10.2026 Kundennummer 456';

    $result = evaluate($letter, [['terms' => ['Rechnung', '456'], 'target' => 'Belege/{Jahr}', 'name' => '{JJJJ.MM.TT}_Rechnung.pdf']]);
    check('all words found', [$result['status'], $result['destination']], ['match', 'Belege/2026/2026.10.14_Rechnung.pdf']);

    check('one word missing', evaluate($letter, [['terms' => ['Rechnung', 'Mahnung']]])['status'], 'none');
    check('or list, none found', evaluate($letter, [['any' => ['Mahnung', 'Angebot']]])['status'], 'none');
    check('or list names the file', evaluate($letter, [['any' => ['Mahnung', 'Rechnung'], 'name' => '{Treffer}.pdf']])['destination'], 'Ziel/Rechnung.pdf');
    check('and and or combined', evaluate($letter, [['terms' => ['456'], 'any' => ['Mahnung']]])['status'], 'none');
    check('blank name keeps the original', evaluate($letter, [['terms' => ['456']]])['destination'], 'Ziel/scan.pdf');

    check('date missing', evaluate('Rechnung ohne Datum', [['terms' => ['Rechnung'], 'name' => '{Datum}.pdf']])['status'], 'none');
    check('date in the folder missing', evaluate('Rechnung ohne Datum', [['terms' => ['Rechnung'], 'target' => 'Belege/{Jahr}']])['status'], 'none');
    check('date after a word', evaluate('01.01.2020 Datum: 02.02.2022 Rechnung', [['terms' => ['Rechnung'], 'after' => 'Datum', 'name' => '{Jahr}.pdf']])['destination'], 'Ziel/2022.pdf');

    check('disabled rule skipped', evaluate($letter, [['terms' => ['Rechnung'], 'enabled' => false]])['rules'], []);
    check('first matching rule wins', evaluate($letter, [['terms' => ['Rechnung'], 'target' => 'Erste'], ['terms' => ['456'], 'target' => 'Zweite']])['destination'], 'Erste/scan.pdf');
    check('no rule of another user', evaluate($letter, [['user' => 'ben', 'terms' => ['Rechnung']]])['rules'], []);

    $result = evaluate($letter, [['terms' => ['Mahnung']]], 'Unsortiert');
    check('unmatched folder', [$result['status'], $result['destination']], ['unmatched', 'Unsortiert/scan.pdf']);
    check('no text layer', evaluate(" \n ", [['terms' => ['Rechnung']]], 'Unsortiert')['status'], 'no_text');

    $mapper->rules = [
        rule(['category' => 'Auto', 'subcategory' => 'Alt']),
        rule(['category' => 'Haus', 'subcategory' => 'Alt']),
        rule(['category' => 'Auto', 'subcategory' => 'Alt', 'user' => 'ben']),
    ];
    $groups = static fn () => array_map(static fn (Rule $rule) => $rule->getCategory() . '/' . $rule->getSubcategory(), $mapper->rules);

    check('rename subcategory', $controller->renameSubcategory('Auto', 'Alt', ' Neu ')->data, ['name' => 'Neu']);
    check('only that category, only own rules', $groups(), ['Auto/Neu', 'Haus/Alt', 'Auto/Alt']);
    check('rename category', $controller->renameCategory('Auto', 'KFZ')->data, ['name' => 'KFZ']);
    check('category of own rules only', $groups(), ['KFZ/Neu', 'Haus/Alt', 'Auto/Alt']);
    check('empty name refused', $controller->renameCategory('KFZ', '  ')->status, 400);
    check('long name refused', $controller->renameSubcategory('KFZ', 'Neu', str_repeat('a', 101))->status, 400);
    check('nothing changed by refused names', $groups(), ['KFZ/Neu', 'Haus/Alt', 'Auto/Alt']);

    check('rule needs a word', $controller->create([], [], 'Ziel')->status, 400);
    check('target must stay inside the files', $controller->create(['a'], [], 'Ziel/../../x')->status, 400);
    check('rule of another user is hidden', $controller->update($mapper->rules[2]->getId(), ['a'], [], 'Ziel')->status, 404);
    check('rule of another user cannot be deleted', $controller->delete($mapper->rules[2]->getId())->status, 404);

    finish();
}
