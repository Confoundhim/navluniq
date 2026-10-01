<?php

namespace App\Support;

/**
 * Hazır MacroDroid makrosu (.macro dosyası): Facebook'ta açık olan akışı (Gruplar ya da bir grubun içi) tek dokunuşla kaydırıp
 * okur ve sunucuya yollar. Engin Abi dosyayı telefonda MacroDroid'e içe aktarır; elle kurulum gerekmez. Anahtar ve adres içindedir.
 *
 * Yapı, MacroDroid'in dışa aktardığı JSON biçimidir (macroExportVersion 1); alan adları Osman'ın telefonundan alınan gerçek
 * dışa aktarımdan (2026-09-30) birebir kopyalandı. Akış: kayan düğme → sabit sayıda yinelenen döngüde ekran içeriğini oku (sözlük
 * değişkeni), biriktir, yukarı kaydır, bekle → HTTP POST (düz metin gövde, anahtar ve tür başlıkta) → bildirim. Facebook açılmaz ve
 * hiçbir şeye dokunulmaz: "fb://groups" bağlantısı ana sayfaya fırlatıyordu, "diğer" düğmesine dokunma adımı makroyu durduruyordu
 * (Osman, 2026-10-01). Ekran içeriği her turda "-----" ayracıyla JSON (lvjson) biçiminde eklenir; sunucu
 * (NotificationIntakeParser::parseFacebookScreen) erişilebilirlik dökümünü gönderilere böler. Kısaltılmış gönderi "… diğer" ile gelir.
 */
final class MacroDroidMacro
{
    public const FILENAME = 'navluniq-macro-final-v1.macro';

    public const NAME = 'navluniq macro final v1';

    /** @return array<string, mixed> */
    public static function facebookFeed(string $url, string $token, int $screens = 15): array
    {
        $ekran = self::variable('ekran', 2);
        $parca = self::variable('parca', 4);

        $actions = [
            // Facebook açılmaz, sayfa değiştirilmez: Osman neredeyse (Gruplar akışı ya da bir grubun içi) orası kaydırılır.
            // "fb://groups" derin bağlantısı ana sayfaya fırlatıyordu; "diğer" düğmesine dokunma adımı makroyu baştan durduruyordu.
            self::pause(1), // düğmeye dokunuş bitsin
            self::action('SetVariableAction', self::setString($ekran, '')),
            // m_option 0 = sabit sayıda yinele (1 = koşul sürdükçe: sonsuz dönüyordu)
            self::action('LoopAction', ['m_fixedOptionCount' => $screens, 'm_option' => 0, 'timedDurationValue' => 1, 'timedTimeUnit' => 0, 'childrenCollapsed' => false, 'dontLogIfConditionIsFalse' => false]),
            self::action('ReadScreenContentsAction', ['dictionaryKeys' => ['keys' => []], 'forceScreenRefresh' => false, 'includeOverlays' => false, 'includeScreenLocation' => false, 'includeWithoutText' => false, 'isLocalVar' => true, 'variableName' => 'parca'], comment: false),
            self::action('SetVariableAction', self::setString($ekran, "{lv=ekran}\n-----\n{lvjson=parca}")),
            // Yukarı kaydırma: ekranın %75'inden %25'ine, yarım saniye (kısa/hızlı hareket dokunma sanılmasın)
            self::action('UIInteractionAction', ['action' => 6, 'uiInteractionConfiguration' => [
                'additionalFingers' => 0, 'durationMs' => 500, 'endX' => 50, 'endX2' => 0, 'endY' => 25, 'endY2' => 0, 'showTouchLocation' => false,
                'startX' => 50, 'startX2' => 0, 'startY' => 75, 'startY2' => 0, 'waitBeforeNext' => true, 'xyPercentages' => true, 'type' => 'Gesture',
            ]]),
            self::pause(1, 500),
            self::action('EndLoopAction', []),
            self::action('HttpRequestAction', ['requestConfig' => [
                'allFilesAccessPath' => '', 'allowAnyCertificate' => false, 'basicAuthEnabled' => false, 'basicAuthPassword' => '', 'basicAuthUsername' => '', 'blockNextAction' => true,
                'clientCertEnabled' => false, 'clientCertKeyStoreDisplayName' => '', 'clientCertKeyStoreUri' => '', 'clientCertPassword' => '',
                'contentBodyDynamicFileName' => '', 'contentBodyFileDisplayName' => '', 'contentBodyFileUri' => '', 'contentBodyFolderDisplayName' => '', 'contentBodyFolderUri' => '',
                // Gövde düz metin: ekran içeriği tırnak/satır sonu taşır, JSON'a gömülünce bozulurdu. Anahtar ve tür başlıkta gider.
                'contentBodySource' => 0, 'contentBodyText' => '{lv=ekran}', 'contentType' => 'text/plain', 'followRedirects' => true,
                'headerParams' => [['paramName' => 'X-Scraper-Token', 'paramValue' => $token], ['paramName' => 'X-Intake-Kind', 'paramValue' => 'screen']],
                'localFileUri' => '', 'maxTotalDurationSeconds' => 3600, 'prettifyJson' => false, 'proxyEnabled' => false, 'proxyHost' => '', 'proxyPort' => 8080, 'proxyType' => 0,
                'queryParams' => [], 'requestTimeOutSeconds' => 60, 'requestType' => 1, // 1 = POST (0 GET olarak içe aktarılıyordu)
                'saveResponseAllFilesAccessPath' => '', 'saveResponseFileName' => '', 'saveResponseFolderPathDisplayName' => '', 'saveResponseFolderPathUri' => '', 'saveResponseType' => 0,
                'saveResponseUseAllFilesAccess' => false, 'saveReturnCodeToVariable' => false, 'saveReturnHeadersToVariable' => false, 'urlToOpen' => $url,
                'useAllFilesAccess' => false, 'useLocalFileUri' => false, 'useStaticContentBodyFile' => true,
            ]]),
            self::action('NotificationAction', [
                'autoExpand' => true, 'blockNextAction' => false, 'dimBackground' => true, 'disableHtml' => false, 'displayOverStatusBar' => false, 'iconText' => '', 'iconType' => 0,
                'liveNotification' => false, 'm_backgroundColor' => -16777216, 'm_iconBgColor' => -1762269, 'm_imageResourceId' => 0, 'm_macroGUIDToRun' => 0, 'm_notificationChannelType' => 0,
                'm_notificationSubject' => 'NavlunIQ', 'm_notificationText' => 'Akış gönderildi', 'm_overwriteExisting' => true, 'm_priority' => 0, 'm_ringtoneIndex' => 0, 'm_ringtoneName' => 'Default',
                'm_runMacroWhenPressed' => false, 'm_textColor' => -1, 'maintainSpaces' => false, 'notificationActionButtons' => [], 'notificatonId' => 0, 'preventAndroid16Grouping' => false,
                'preventBackButtonClosing' => false, 'preventRemovalByBin' => false, 'showAsOverlayOption' => 1, 'yPosition' => 0.5,
            ]),
        ];

        $trigger = [
            // disableTriggerOnRemove false: düğme çöp kutusuna sürüklenince yalnız gizlenir, tetikleyici kapanmaz (Osman'ın telefonunda
            // tetikleyici kapalı kalmış, simge çıkmıyordu). Geri getirmek için makronun anahtarı kapatılıp açılır.
            'disableTriggerOnRemove' => false, 'drawOverSystemApps' => false, 'fixedLocation' => false, 'forceLocation' => false, 'iconText' => 'NQ', 'iconTextColor' => -1,
            'iconTintColor' => -1, 'iconTintEnabled' => false, 'iconType' => 0, 'identifier' => 'nq', 'longPressEnabled' => false, 'm_alpha' => 100, 'm_iconBgColor' => -1024000,
            'm_imageResourceId' => 0, 'm_padding' => 20, 'm_showOnLockScreen' => true, 'm_size' => 0, 'm_transparentBackground' => false, 'overridenAlpha' => 0, 'overridenBgColor' => 0,
            'overridenSize' => 0, 'overridenTransparentBackground' => false, 'preventRemoveByDrag' => false, 'showPositionOnMove' => false, 'usePercentForLocation' => false,
            'vibrateOnClick' => true, 'xLocation' => 0, 'yLocation' => 0,
            'disableLogging' => false, 'm_SIGUID' => self::guid(), 'm_classType' => 'FloatingButtonTrigger', 'm_comment' => '', 'm_constraintList' => [], 'm_isDisabled' => false, 'm_isOrCondition' => false,
        ];

        return [
            'globalVariables' => [],
            'macro' => [
                'aiGenerated' => 0, 'breakpoints' => [], 'disabledTimestamp' => 0, 'exportedActionBlocks' => [], 'forceEvenIfNotEnabledTimestamp' => 0,
                'isActionBlock' => false, 'isExtra' => false, 'isFavourite' => false, 'lastEditedTimestamp' => (int) (microtime(true) * 1000),
                'localVariables' => [$ekran, $parca], 'localVarsAlphabetical' => true, 'loggingLevel' => 0, 'm_GUID' => self::guid(),
                'm_actionList' => $actions, 'm_category' => '', 'm_completed' => true, 'm_constraintList' => [], // kategorisiz: kapalı kategori makroyu durduruyordu
                'm_description' => 'Facebook\'ta Gruplar akışında ya da bir grubun içinde NQ düğmesine dokunun: '.$screens.' ekran kaydırıp okur ve NavlunIQ sunucusuna yollar. Bu sırada telefona dokunmayın.',
                'm_descriptionOpen' => false, 'm_enabled' => true, 'm_excludeLog' => false, 'm_headingColor' => -855310, 'm_isOrCondition' => false,
                'm_name' => self::NAME, 'm_triggerList' => [$trigger],
            ],
            'macroExportVersion' => 1,
        ];
    }

    public static function json(string $url, string $token, int $screens = 15): string
    {
        return json_encode(self::facebookFeed($url, $token, $screens), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string, mixed> */
    private static function pause(int $seconds, int $millis = 0): array
    {
        return self::action('PauseAction', ['m_delayInMilliSeconds' => $millis, 'm_delayInSeconds' => $seconds, 'm_useAlarm' => false, 'unitForVariables' => 0]);
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
            'm_trueLabel' => 'True', 'm_userPrompt' => false, 'm_userPromptEmptyAtStart' => false, 'm_userPromptPassword' => false, 'm_userPromptPasswordToggle' => false,
            'm_userPromptShowCancel' => true, 'm_userPromptStopAfterCancel' => true, 'm_variable' => $variable,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function action(string $class, array $fields, bool $comment = true): array
    {
        $common = ['disableLogging' => false, 'm_SIGUID' => self::guid(), 'm_classType' => $class, 'm_comment' => '', 'm_constraintList' => [], 'm_isDisabled' => false, 'm_isOrCondition' => false];
        if (! $comment) {
            unset($common['m_comment']); // ReadScreenContentsAction dışa aktarımda m_comment taşımaz
        }

        return $fields + $common;
    }

    private static function guid(): int
    {
        return -random_int(1000000000000000000, 9000000000000000000);
    }
}
