<?php

namespace App\Support;

/**
 * Hazır MacroDroid makrosu (.macro dosyası): Facebook "Gruplar" akışını tek dokunuşla toplayıp sunucuya yollar.
 * Engin Abi dosyayı telefonda MacroDroid'e içe aktarır; elle kurulum gerekmez. Anahtar ve adres dosyanın içine yazılır.
 *
 * Yapı, MacroDroid'in dışa aktardığı JSON biçimidir (macroExportVersion 1): kayan düğme tetikleyicisi, 15 kez yinelenen
 * döngüde "Devamını gör"e dokunma, ekran içeriğini okuma (sözlük değişkeni), biriktirme, yukarı kaydırma, bekleme; döngü
 * sonunda HTTP POST ve bildirim. Başta "fb://groups" derin bağlantısıyla Facebook'un Gruplar sekmesi açılır (Osman ana akışta
 * basınca ana akış kaymıştı; artık nereden basılırsa basılsın Gruplar akışı kaydırılır). Ekran içeriği her turda "-----" ayracıyla ve JSON (lvjson) biçiminde eklenir; sunucu
 * (NotificationIntakeParser::parseFacebookScreen) JSON sözlüğün değerlerini satır satır alır.
 */
final class MacroDroidMacro
{
    public const FILENAME = 'navluniq-akis.macro';

    /** @return array<string, mixed> */
    public static function facebookFeed(string $url, string $token, int $screens = 15): array
    {
        $ekran = self::variable('ekran', 2);
        $parca = self::variable('parca', 4);
        $body = json_encode(['app' => 'Facebook', 'kind' => 'screen', 'title' => 'ekran', 'text' => '{lv=ekran}', 'token' => $token], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $actions = [
            // Önce Facebook'un Gruplar sekmesi açılır (derin bağlantı); böylece düğmeye nerede basıldığı fark etmez.
            self::action('OpenWebPageAction', ['m_urlToOpen' => 'fb://groups', 'm_httpGet' => false, 'm_disableUrlEncode' => true, 'm_blockNextAction' => false]),
            self::action('PauseAction', ['m_delayInMilliSeconds' => 0, 'm_delayInSeconds' => 4, 'm_useAlarm' => false, 'unitForVariables' => 0]),
            self::action('SetVariableAction', self::setString($ekran, '')),
            self::action('LoopAction', ['m_fixedOptionCount' => $screens, 'm_option' => 1, 'childrenCollapsed' => false, 'dontLogIfConditionIsFalse' => false]),
            self::action('UIInteractionAction', ['action' => 0, 'uiInteractionConfiguration' => [
                'blocking' => false, 'checkOverlays' => false, 'clickOption' => 1, 'longClick' => false, 'textContent' => 'Devamını gör',
                'textMatchOption' => 1, 'viewId' => '', 'xyPercentages' => false, 'type' => 'Click',
            ]]),
            self::action('PauseAction', ['m_delayInMilliSeconds' => 0, 'm_delayInSeconds' => 1, 'm_useAlarm' => false, 'unitForVariables' => 0]),
            self::action('ReadScreenContentsAction', ['excludeViewsWithoutText' => true, 'includeOverlays' => false, 'm_variable' => $parca, 'dictionaryKeys' => []]),
            self::action('SetVariableAction', self::setString($ekran, "{lv=ekran}\n-----\n{lvjson=parca}")),
            self::action('UIInteractionAction', ['action' => 6, 'uiInteractionConfiguration' => [
                'additionalFingers' => 0, 'durationMs' => 400, 'endX' => 50, 'endX2' => 0, 'endY' => 20, 'endY2' => 0,
                'startX' => 50, 'startX2' => 0, 'startY' => 80, 'startY2' => 0, 'waitBeforeNext' => true, 'xyPercentages' => true, 'type' => 'Gesture',
            ]]),
            self::action('PauseAction', ['m_delayInMilliSeconds' => 500, 'm_delayInSeconds' => 1, 'm_useAlarm' => false, 'unitForVariables' => 0]),
            self::action('EndLoopAction', []),
            self::action('HttpRequestAction', ['httpRequestConfig' => [
                'allowAnyCertificate' => false, 'basicAuthEnabled' => false, 'basicAuthPassword' => '', 'basicAuthUsername' => '', 'blockNextAction' => true,
                'contentBodyFile' => '', 'contentBodyText' => $body, 'contentBodyType' => 'text', 'contentType' => 'application/json', 'followRedirects' => true,
                'headerParams' => [], 'queryParams' => [], 'requestType' => 'POST', 'saveResponseFileName' => '', 'saveResponseFolderPath' => '',
                'saveResponseFolderPathDisplayName' => '', 'saveResponseType' => 'none', 'timeoutSeconds' => 60, 'urlEncodeBody' => false, 'urlEncodeParams' => false,
                'urlToOpen' => $url,
            ]]),
            self::action('NotificationAction', [
                'm_notificationSubject' => 'NavlunIQ', 'm_notificationText' => 'Akış gönderildi', 'm_ringtoneName' => 'Default', 'm_macroGuidToRun' => 0,
                'm_notificationChannelType' => 0, 'm_imageResourceId' => 0, 'm_overwriteExisting' => true, 'm_priority' => 0, 'm_ringtoneIndex' => 0,
                'm_iconBgColor' => -1762269, 'm_runMacroWhenPressed' => false, 'm_notificationSoundOff' => true,
            ]),
        ];

        $trigger = [
            'identifier' => 'nq', 'iconText' => 'NQ', 'useTextIcon' => true, 'iconTextColor' => -1, 'imageResourceName' => '', 'm_imageResourceId' => 0,
            'm_iconBgColor' => -1024000, 'm_alpha' => 100, 'm_padding' => 20, 'm_forceLocation' => false, 'm_showOnLockScreen' => true, 'm_size' => 0,
            'm_transparentBackground' => false, 'm_xLocation' => 0, 'm_yLocation' => 0, 'preventRemoveByDrag' => false, 'vibrateOnPress' => true, 'detectLongPress' => false, // çöp kutusuna sürükleyince makro kapanır (Osman istedi)
            'disableLogging' => false, 'm_SIGUID' => self::guid(), 'm_classType' => 'FloatingButtonTrigger', 'm_comment' => '', 'm_constraintList' => [], 'm_isDisabled' => false, 'm_isOrCondition' => false,
        ];

        return [
            'globalVariables' => [],
            'macro' => [
                'aiGenerated' => 0, 'breakpoints' => [], 'disabledTimestamp' => 0, 'exportedActionBlocks' => [], 'forceEvenIfNotEnabledTimestamp' => 0,
                'isActionBlock' => false, 'isExtra' => false, 'isFavourite' => false, 'lastEditedTimestamp' => (int) (microtime(true) * 1000),
                'localVariables' => [$ekran, $parca], 'localVarsAlphabetical' => true, 'm_GUID' => self::guid(),
                'm_actionList' => $actions, 'm_category' => 'NavlunIQ', 'm_constraintList' => [],
                'm_description' => 'Facebook Gruplar akışını 15 ekran kaydırıp okur ve NavlunIQ sunucusuna yollar. Facebook → Gruplar sekmesi → NQ düğmesine dokunun.',
                'm_descriptionOpen' => false, 'm_enabled' => true, 'm_excludeLog' => false, 'm_headingColor' => 0, 'm_isOrCondition' => false,
                'm_name' => 'NavlunIQ akış', 'm_triggerList' => [$trigger],
            ],
            'macroExportVersion' => 1,
        ];
    }

    public static function json(string $url, string $token, int $screens = 15): string
    {
        return json_encode(self::facebookFeed($url, $token, $screens), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string, mixed> */
    private static function variable(string $name, int $type): array
    {
        return [
            'dictionary' => ['entries' => [], 'isArray' => false, 'variableType' => 4, 'type' => 'Dictionary'],
            'isActionBlockWorkingVar' => false, 'isLocalVar' => true, 'isSecure' => false, 'm_booleanValue' => false, 'm_decimalValue' => 0.0,
            'm_intValue' => 0, 'm_name' => $name, 'm_stringValue' => '', 'm_type' => $type, 'supportsInput' => false, 'supportsOutput' => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function setString(array $variable, string $value): array
    {
        return [
            'booleanDictionaryKeys' => ['keys' => []], 'dictionaryKeys' => [], 'dictionaryOrArrayType' => -1, 'existingManualKeyType' => 0,
            'm_booleanInvert' => false, 'm_darkMode' => -1, 'm_doubleRandomMax' => 0.0, 'm_doubleRandomMin' => 0.0, 'm_falseLabel' => 'False',
            'm_intExpression' => false, 'm_intRandom' => false, 'm_intRandomMax' => 0, 'm_intRandomMin' => 0, 'm_intValueDecrement' => false,
            'm_intValueIncrement' => false, 'm_newBooleanValue' => false, 'm_newDoubleValue' => 0.0, 'm_newIntValue' => 0, 'm_newStringValue' => $value,
            'm_trueLabel' => 'True', 'm_userPrompt' => false, 'm_userPromptEmptyAtStart' => false, 'm_userPromptPassword' => false,
            'm_userPromptShowCancel' => true, 'm_userPromptStopAfterCancel' => true, 'm_variable' => $variable,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function action(string $class, array $fields): array
    {
        return $fields + ['disableLogging' => false, 'm_SIGUID' => self::guid(), 'm_classType' => $class, 'm_comment' => '', 'm_constraintList' => [], 'm_isDisabled' => false, 'm_isOrCondition' => false];
    }

    private static function guid(): int
    {
        return -random_int(1000000000000000000, 9000000000000000000);
    }
}
