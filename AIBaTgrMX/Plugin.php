<?php

/**
 * AIBaTgrMX 是一个多功能AI助手插件，包含文章摘要、标签生成、分类推荐、SEO优化
 *
 * @package AIBaTgrMX
 * @author Looks
 * @version 2.0
 * @link https://blog.tgrmx.cn
 */
class AIBaTgrMX_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        if (!method_exists('Typecho_Plugin', 'factory')) {
            throw new Typecho_Plugin_Exception(_t('无法加载插件，请确认 Typecho 版本'));
        }

        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            self::createTables($db, $prefix);

            Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('AIBaTgrMX_Plugin', 'customExcerpt');
            Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array('AIBaTgrMX_Plugin', 'beforePublish');
            Typecho_Plugin::factory('Widget_Archive')->header = array('AIBaTgrMX_Plugin', 'optimizeSEO');

            return _t('插件启用成功');

        } catch (Exception $e) {
            throw new Typecho_Plugin_Exception(_t('插件启用失败，错误信息：%s', $e->getMessage()));
        }
    }

    private static function createTables($db, $prefix)
    {
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}ai_content` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `cid` int(10) unsigned NOT NULL,
            `type` varchar(20) NOT NULL,
            `content` text,
            `created` int(10) unsigned DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `cid` (`cid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $provider = new Typecho_Widget_Helper_Form_Element_Select(
            'provider',
            array(
                'deepseek' => 'DeepSeek API',
                'openai' => 'OpenAI API',
                'custom' => '自定义 API'
            ),
            'deepseek',
            _t('API 提供商'),
            _t('选择要使用的 API 服务提供商')
        );
        $form->addInput($provider);

        $customApiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'customApiUrl',
            NULL,
            '',
            _t('自定义 API 地址'),
            _t('当选择"自定义 API"时，请输入完整的API接口地址')
        );
        $form->addInput($customApiUrl);

        $keyValue = new Typecho_Widget_Helper_Form_Element_Text(
            'keyValue',
            NULL,
            NULL,
            _t('API 密钥'),
            _t('输入您的 API 密钥')
        );
        $form->addInput($keyValue);

        $blogIdentifier = new Typecho_Widget_Helper_Form_Element_Text(
            'blogIdentifier',
            NULL,
            'blog.tgrmx.cn',
            _t('作者博客标识'),
            _t('请勿修改此项，用于插件功能的正常使用')
        );
        $form->addInput($blogIdentifier);

        $modelName = new Typecho_Widget_Helper_Form_Element_Select(
            'modelName',
            array(
                'deepseek-chat' => 'DeepSeek Chat',
                'gpt-4' => 'GPT-4',
                'gpt-4-turbo' => 'GPT-4 Turbo',
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                'gpt-3.5-turbo-16k' => 'GPT-3.5 Turbo 16K',
                'custom' => '自定义模型'
            ),
            'deepseek-chat',
            _t('AI 模型'),
            _t('选择要使用的 AI 模型')
        );
        $form->addInput($modelName);

        $customModel = new Typecho_Widget_Helper_Form_Element_Text(
            'customModel',
            NULL,
            '',
            _t('自定义模型名称'),
            _t('当选择"自定义模型"时，请输入完整的模型名称')
        );
        $form->addInput($customModel);

        echo '<style>
            #typecho-option-item-customApiUrl-1,
            #typecho-option-item-customModel-5 {
                display: none;
            }
        </style>';
        echo '<script>
        function updateVisibility() {
            var provider = document.getElementsByName("provider")[0];
            var model = document.getElementsByName("modelName")[0];
            var customApiUrlOption = document.getElementById("typecho-option-item-customApiUrl-1");
            var customModelOption = document.getElementById("typecho-option-item-customModel-5");
            
            if (!provider || !model || !customApiUrlOption || !customModelOption) {
                console.log("Some elements not found");
                return;
            }
            
            customApiUrlOption.style.display = provider.value === "custom" ? "block" : "none";
            customModelOption.style.display = model.value === "custom" ? "block" : "none";
        }

        window.addEventListener("DOMContentLoaded", function() {
            var providerSelect = document.getElementsByName("provider")[0];
            var modelSelect = document.getElementsByName("modelName")[0];
            
            if (providerSelect && modelSelect) {
                providerSelect.addEventListener("change", updateVisibility);
                modelSelect.addEventListener("change", updateVisibility);
                updateVisibility();
            }
        });
        </script>';

        $features = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'features',
            array(
                'summary' => _t('自动生成文章摘要'),
                'tags' => _t('自动生成标签'),
                'category' => _t('分类推荐'),
                'seo' => _t('SEO优化')
            ),
            array('summary'),
            _t('启用功能'),
            _t('选择要启用的 AI 功能')
        );
        $form->addInput($features);

        $maxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'maxLength',
            NULL,
            '100',
            _t('摘要长度'),
            _t('自动生成摘要的最大字数')
        );
        $form->addInput($maxLength);

        $maxTags = new Typecho_Widget_Helper_Form_Element_Text(
            'maxTags',
            NULL,
            '5',
            _t('标签数量'),
            _t('自动生成的标签数量上限（1-10）')
        );
        $form->addInput($maxTags->addRule('required', _t('请填写标签数量'))
            ->addRule('isInteger', _t('标签数量必须是整数'))
            ->addRule('min', _t('标签数量必须大于等于1'), 1)
            ->addRule('max', _t('标签数量必须小于等于10'), 10));

        $language = new Typecho_Widget_Helper_Form_Element_Select(
            'language',
            array(
                'zh' => _t('中文'),
                'en' => _t('英文'),
                'ja' => _t('日语'),
                'ko' => _t('韩语'),
                'fr' => _t('法语'),
                'de' => _t('德语'),
                'es' => _t('西班牙语'),
                'ru' => _t('俄语')
            ),
            'zh',
            _t('输出语言'),
            _t('选择 AI 生成内容的语言')
        );
        $form->addInput($language);

        $cacheTime = new Typecho_Widget_Helper_Form_Element_Text(
            'cacheTime',
            NULL,
            '86400',
            _t('缓存时间'),
            _t('AI生成内容的缓存时间（秒），默认24小时')
        );
        $form->addInput($cacheTime);

        $defaultCategory = new Typecho_Widget_Helper_Form_Element_Text(
            'defaultCategory',
            NULL,
            '默认分类',
            _t('默认分类'),
            _t('当无法确定合适分类时使用的默认分类名称')
        );
        $form->addInput($defaultCategory);

        $summaryPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'summaryPrompt',
            NULL,
            "你是一个专业的文章摘要生成专家。请严格按照以下要求执行：

1. 输入：完整的文章内容
2. 输出语言：{{LANGUAGE}}
3. 输出：简洁的摘要
4. 限制条件：
   - 最大长度：{{MAX_LENGTH}}字符
   - 保持关键信息密度
   - 保留原文语气和风格
   - 聚焦核心概念和发现
5. 格式：纯文本，无标记
6. 质量检查：
   - 事实准确性
   - 逻辑连贯性
   - 信息完整性
   - 语言一致性",
            _t('摘要生成提示词'),
            _t('用于生成文章摘要的系统提示词，支持变量：{{LANGUAGE}}、{{MAX_LENGTH}}')
        );
        $form->addInput($summaryPrompt);

        $tagsPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'tagsPrompt',
            NULL,
            "你是一个语义标签系统。请执行以下标签生成协议：

1. 输入分析：
   - 扫描专业术语
   - 识别关键概念
   - 提取领域特定术语
   - 检测重复主题

2. 标签生成规则：
   - 最大标签数：{{MAX_TAGS}}
   - 标签格式：小写，无空格
   - 长度：每个标签2-30个字符
   - 分隔符：逗号(,)
   - 输出语言：{{LANGUAGE}}

3. 标签优先级：
   - 技术准确性：40%
   - 搜索相关性：30%
   - 特异性：20%
   - 流行度：10%

4. 验证标准：
   - 无重复
   - 避免宽泛/通用术语
   - 必须具有上下文相关性
   - 必须具有搜索价值",
            _t('标签生成提示词'),
            _t('用于生成文章标签的系统提示词，支持变量：{{LANGUAGE}}、{{MAX_TAGS}}')
        );
        $form->addInput($tagsPrompt);

        $categoryPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'categoryPrompt',
            NULL,
            "你是一个专业的文章分类专家。请按照以下规则为文章选择最合适的分类：

1. 分析要点：
   - 文章的主要主题和核心内容
   - 文章的写作风格和表达方式
   - 目标读者群体
   - 文章的实用价值
   - 内容的专业领域

2. 分类规则：
   - 必须从现有分类中选择一个
   - 优先考虑内容的主要主题
   - 考虑读者检索和浏览习惯
   - 避免过于宽泛的分类

3. 内容类型判断：
   - 美食类：食谱、菜品制作、烹饪技巧、饮食健康等
   - 技术类：编程、开发、系统架构、技术教程等
   - 生活类：日常分享、心得体会、生活技巧等
   - 其他类：根据具体内容特征判断

4. 权重考虑：
   - 主题相关度：60%
   - 内容类型：30%
   - 读者需求：10%

5. 输出要求：
   - 仅返回一个分类名称
   - 必须完全匹配现有分类列表
   - 区分大小写
   - 不要添加任何额外格式

6. 验证步骤：
   - 确认分类存在于列表中
   - 验证主题匹配度
   - 检查分类的准确性
   - 确保选择最具体的分类

可选分类列表：{{CATEGORIES}}

特别说明：
1. 对于食谱、菜品制作等内容必须选择'美食'分类
2. 只有涉及技术开发、编程等内容才选择'技术'分类
3. 优先选择最符合文章主题的具体分类",
            _t('分类推荐提示词'),
            _t('用于推荐文章分类的系统提示词，支持变量：{{LANGUAGE}}、{{CATEGORIES}}')
        );
        $form->addInput($categoryPrompt);

        $seoPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'seoPrompt',
            NULL,
            "你是一个SEO优化引擎。请执行以下优化协议：

1. 内容分析：
   - 核心主题识别
   - 关键信息提取
   - 价值主张检测
   - 受众定位

2. 描述要求：
   - 最大长度：{{SEO_LENGTH}}字符
   - 包含主要关键词
   - 保持可读性
   - 优化点击率

3. 关键词选择标准：
   - 搜索量潜力
   - 竞争程度
   - 相关性评分
   - 用户意图匹配

4. 输出格式：
   {
     \"description\": \"优化后的元描述\",
     \"keywords\": \"关键词1,关键词2,关键词3\"
   }

5. 质量参数：
   - 关键词密度：2-3%
   - 自然语言流畅度
   - 行动导向措辞
   - 独特价值主张

6. 技术约束：
   - 有效的JSON格式
   - UTF-8编码
   - 无HTML实体
   - 正确转义",
            _t('SEO优化提示词'),
            _t('用于生成SEO信息的系统提示词，支持变量：{{LANGUAGE}}、{{SEO_LENGTH}}')
        );
        $form->addInput($seoPrompt);
    }

    public static function deactivate()
    {
        return _t('插件禁用成功');
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        return true;
    }

    public static function customExcerpt($excerpt, $widget)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            $features = $options->features ? $options->features : array();

            if (!in_array('summary', $features)) {
                return $excerpt;
            }

            $db = Typecho_Db::get();
            $customContent = $db->fetchRow($db->select('str_value')
                ->from('table.fields')
                ->where('cid = ?', $widget->cid)
                ->where('name = ?', 'content'));

            if ($customContent && !empty($customContent['str_value'])) {
                $excerpt = $customContent['str_value'];
            } else {
                $prompt = "请为以下文章生成一个简短的摘要，字数不超过{$options->maxLength}字：\n\n{$widget->text}";
                $summary = self::callApi($prompt, 'summary');

                if (!empty($summary)) {
                    if ($customContent) {
                        $db->query($db->update('table.fields')
                            ->rows(array('str_value' => $summary))
                            ->where('cid = ?', $widget->cid)
                            ->where('name = ?', 'content'));
                    } else {
                        $db->query($db->insert('table.fields')
                            ->rows(array(
                                'cid' => $widget->cid,
                                'name' => 'content',
                                'type' => 'str',
                                'str_value' => $summary,
                                'int_value' => 0,
                                'float_value' => 0
                            )));
                    }
                    $excerpt = $summary;
                }
            }

            if (mb_strlen($excerpt) > $options->maxLength) {
                $excerpt = mb_substr($excerpt, 0, $options->maxLength) . '...';
            }

            return $excerpt;
        } catch (Exception $e) {
            error_log('AI摘要生成错误: ' . $e->getMessage());
            return $excerpt;
        }
    }

    public static function beforePublish($contents, $obj)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        $features = $options->features ? $options->features : array();

        try {
            $tasks = array();
            $text = $contents['text'];
            $segments = array();
            $maxLength = 3000;

            if (mb_strlen($text) > $maxLength) {
                $paragraphs = preg_split('/\n\s*\n/', $text);
                $currentSegment = '';

                foreach ($paragraphs as $paragraph) {
                    if (mb_strlen($currentSegment . "\n\n" . $paragraph) <= $maxLength) {
                        $currentSegment .= ($currentSegment ? "\n\n" : '') . $paragraph;
                    } else {
                        if ($currentSegment) {
                            $segments[] = $currentSegment;
                        }
                        $currentSegment = $paragraph;
                    }
                }
                if ($currentSegment) {
                    $segments[] = $currentSegment;
                }
            } else {
                $segments[] = $text;
            }

            if (in_array('summary', $features)) {
                $tasks[] = function() use ($segments, $options, $obj) {
                    $summaries = array();
                    foreach ($segments as $segment) {
                        $prompt = "请为以下文章片段生成摘要，字数不超过{$options->maxLength}字：\n\n{$segment}";
                        $summaries[] = self::callApi($prompt, 'summary');
                    }

                    $summary = implode(' ', $summaries);
                    if (mb_strlen($summary) > $options->maxLength) {
                        $summary = mb_substr($summary, 0, $options->maxLength) . '...';
                    }

                    self::saveField($obj->cid, 'content', $summary);
                    return $summary;
                };
            }

            if (in_array('tags', $features)) {
                $tasks[] = function() use ($segments, $options) {
                    $allTags = array();
                    foreach ($segments as $segment) {
                        $tags = self::generateTags($segment);
                        if (!empty($tags)) {
                            $allTags = array_merge($allTags, explode(',', $tags));
                        }
                    }

                    $allTags = array_unique($allTags);
                    $allTags = array_slice($allTags, 0, $options->maxTags);
                    return implode(',', $allTags);
                };
            }

            if (in_array('category', $features) && empty($contents['category'])) {
                $tasks[] = function() use ($segments) {
                    $suggestedMid = self::suggestCategory($segments[0]);
                    if (!empty($suggestedMid)) {
                        return array($suggestedMid);
                    }
                    return null;
                };
            }

            $results = self::executeTasksConcurrently($tasks);

            foreach ($results as $index => $result) {
                if ($index === 0 && in_array('summary', $features)) {
                } elseif ($index === 1 && in_array('tags', $features) && !empty($result)) {
                    $contents['tags'] = empty($contents['tags']) ? $result : $contents['tags'] . ',' . $result;
                } elseif ($index === 2 && in_array('category', $features) && !empty($result)) {
                    $contents['category'] = $result;
                }
            }

            return $contents;
        } catch (Exception $e) {
            error_log('Content Processing Error: ' . $e->getMessage());
            return $contents;
        }
    }

    private static function executeTasksConcurrently($tasks)
    {
        $results = array();
        $processes = array();

        foreach ($tasks as $index => $task) {
            try {
                $results[$index] = $task();
            } catch (Exception $e) {
                error_log("Task {$index} failed: " . $e->getMessage());
                $results[$index] = null;
            }
        }

        return $results;
    }

    private static function saveField($cid, $name, $value)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select()->from('table.fields')
            ->where('cid = ?', $cid)
            ->where('name = ?', $name));

        if ($row) {
            $db->query($db->update('table.fields')
                ->rows(array('str_value' => $value))
                ->where('cid = ?', $cid)
                ->where('name = ?', $name));
        } else {
            $db->query($db->insert('table.fields')
                ->rows(array(
                    'cid' => $cid,
                    'name' => $name,
                    'type' => 'str',
                    'str_value' => $value,
                    'int_value' => 0,
                    'float_value' => 0
                )));
        }
    }

    public static function optimizeSEO($header, $archive)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        if (!in_array('seo', $options->features)) {
            return;
        }

        try {
            $seoInfo = self::getOrGenerateSEOInfo($archive);

            // 确保 $seoInfo 是数组并且包含必要的键
            if (is_array($seoInfo) && isset($seoInfo['description']) && isset($seoInfo['keywords'])) {
                $description = htmlspecialchars($seoInfo['description'], ENT_QUOTES, 'UTF-8');
                $keywords = htmlspecialchars($seoInfo['keywords'], ENT_QUOTES, 'UTF-8');

                echo '<meta name="description" content="' . $description . '" />' . "\n";
                echo '<meta name="keywords" content="' . $keywords . '" />' . "\n";
            } else {
                // 使用默认值
                $description = mb_substr($archive->excerpt ?? '', 0, 200);
                $keywords = is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '');

                echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
                echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            }
        } catch (Exception $e) {
            error_log('AI SEO Error: ' . $e->getMessage());
            // 发生错误时使用默认值
            $description = mb_substr($archive->excerpt ?? '', 0, 200);
            $keywords = is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '');

            echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
            echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        }
    }

    public static function formatContent($content, $obj)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');

        try {
            if (in_array('format', $options->features)) {
                $content = self::optimizeFormat($content);
            }

            if (in_array('outline', $options->features)) {
                $outline = self::generateOutline($content);
                $content = $outline . $content;
            }

            return $content;
        } catch (Exception $e) {
            error_log('AI Format Error: ' . $e->getMessage());
            return $content;
        }
    }

    private static function callApi($prompt, $type, $retries = 3)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');

        $provider = $options->provider;
        $modelName = $options->modelName === 'custom' ? $options->customModel : $options->modelName;
        $keyValue = $options->keyValue;

        if (empty($keyValue)) {
            throw new Exception('API Key not configured');
        }

        $baseUrl = $options->customApiUrl;
        if (empty($baseUrl)) {
            $baseUrl = $provider === 'deepseek' ? 'https://api.deepseek.com' : 'https://api.openai.com';
        }
        $apiUrl = rtrim($baseUrl, '/') . '/v1/chat/completions';

        $systemPrompt = self::getSystemPrompt($type, $options);

        $data = array(
            'model' => $modelName,
            'messages' => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user', 'content' => $prompt)
            ),
            'temperature' => 0.7,
            'max_tokens' => intval($options->maxLength) * 2,
//            'request_timeout' => 30
        );

        $lastError = null;
        for ($i = 0; $i <= $retries; $i++) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, array(
                    CURLOPT_URL => $apiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                        'Authorization: Bearer ' . $keyValue,
                        'Content-Type: application/json'
                    ),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10
                ));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    throw new Exception('API调用失败: ' . curl_error($ch));
                }
                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception('API请求失败，状态码：' . $httpCode . "\n响应：" . $response);
                }

                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('解析响应失败：' . json_last_error_msg());
                }

                if (isset($result['choices'][0]['message']['content'])) {
                    $pattern = '/```json\s*(.*)\s*```/s';
                    return preg_replace_callback($pattern, function ($matches) {
                        return $matches[1];
                    },trim($result['choices'][0]['message']['content']));
                }

                throw new Exception('API响应格式错误');

            } catch (Exception $e) {
                $lastError = $e;
                if ($i < $retries) {
                    $waitTime = pow(2, $i);
                    error_log("API调用失败，第" . ($i + 1) . "次重试，等待{$waitTime}秒: " . $e->getMessage());
                    sleep($waitTime);
                    continue;
                }
            }
        }

        throw new Exception('API调用失败，已重试' . $retries . '次: ' . $lastError->getMessage());
    }

    private static function getDefaultConfig()
    {
        return array(
            'deepseek' => array(
                'url' => 'https://api.deepseek.com',
                'endpoint' => '/v1/chat/completions'
            ),
            'openai' => array(
                'url' => 'https://api.openai.com',
                'endpoint' => '/v1/chat/completions'
            ),
            'custom' => array(
                'url' => '',
                'endpoint' => ''
            )
        );
    }

    private static function getApiUrl($provider)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        $config = self::getDefaultConfig();

        if ($provider === 'custom') {
            if (empty($options->apiUrl)) {
                throw new Exception('Custom API URL is required');
            }
            return $options->apiUrl;
        }

        $baseUrl = rtrim($options->apiUrl ?: $config[$provider]['url'], '/');
        $endpoint = !empty($options->customEndpoint)
            ? $options->customEndpoint
            : $config[$provider]['endpoint'];
        $endpoint = '/' . ltrim($endpoint, '/');

        return $baseUrl . $endpoint;
    }

    private static function callDeepSeekApi($apiUrl, $model, $systemPrompt, $prompt, $key, $options)
    {
        $data = array(
            "model" => $model,
            "messages" => array(
                array("role" => "system", "content" => $systemPrompt),
                array("role" => "user", "content" => $prompt)
            ),
            "stream" => false,
            "temperature" => floatval($options->temperature ?? 0.7)
        );

        $headers = array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json'
        );

        return self::makeApiRequest($apiUrl, $data, $headers);
    }

    private static function callOpenAIApi($apiUrl, $model, $systemPrompt, $prompt, $key, $options)
    {
        $data = array(
            "model" => $model,
            "messages" => array(
                array("role" => "system", "content" => $systemPrompt),
                array("role" => "user", "content" => $prompt)
            ),
            "temperature" => floatval($options->temperature ?? 0.7)
        );

        $headers = array(
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json'
        );

        return self::makeApiRequest($apiUrl, $data, $headers);
    }

    private static function makeApiRequest($url, $data, $headers)
    {
        $maxRetries = 3;
        $retryDelay = 1;

        for ($i = 0; $i <= $maxRetries; $i++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (curl_errno($ch)) {
                    throw new Exception(curl_error($ch));
                }

                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception("HTTP Error: " . $httpCode . "\nResponse: " . $response);
                }

                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON response");
                }

                if (isset($result['choices'][0]['message']['content'])) {
                    return trim($result['choices'][0]['message']['content']);
                }

                throw new Exception("Unexpected response format");

            } catch (Exception $e) {
                if ($i === $maxRetries) {
                    throw new Exception('API call failed after ' . $maxRetries . ' retries: ' . $e->getMessage());
                }
                error_log('API call attempt ' . ($i + 1) . ' failed: ' . $e->getMessage());
                sleep($retryDelay * ($i + 1));
            }
        }
    }

    private static function handleCache($cid, $type, $callback)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        $cacheTime = intval($options->cacheTime);

        if ($cacheTime < 0) {
            return $callback();
        }

        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            if ($cacheTime == 0) {
                $cache = $db->fetchRow($db->select()
                    ->from('table.ai_content')
                    ->where('cid = ?', $cid)
                    ->where('type = ?', $type));

                if ($cache) {
                    return $cache['content'];
                }
            }

            $cache = $db->fetchRow($db->select()
                ->from('table.ai_content')
                ->where('cid = ?', $cid)
                ->where('type = ?', $type)
                ->where('created > ?', time() - $cacheTime));

            if ($cache) {
                return $cache['content'];
            }

            $content = $callback();

            $db->query($db->delete('table.ai_content')
                ->where('cid = ?', $cid)
                ->where('type = ?', $type));

            $db->query($db->insert('table.ai_content')->rows(array(
                'cid' => $cid,
                'type' => $type,
                'content' => $content,
                'created' => time()
            )));

            return $content;
        } catch (Exception $e) {
            self::handleError('Cache Error', $e);
            return $callback();
        }
    }

    private static function handleError($context, $error)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
        $errorMsg = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $context, $error->getMessage());

        if (in_array('log', $options->errorNotify)) {
            $logFile = __TYPECHO_ROOT_DIR__ . '/usr/logs/ai_assistant.log';
            error_log($errorMsg . "\n", 3, $logFile);

            if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
                rename($logFile, $logFile . '.' . date('Ymd'));
            }
        }

        if (in_array('email', $options->errorNotify) && !empty($options->adminMail)) {
            $mailer = new Typecho_Mail();
            $mailer->setFrom($options->adminMail)
                ->setTo($options->adminMail)
                ->setSubject('AI助手错误通知')
                ->setBody($errorMsg)
                ->send();
        }
    }

    private static function getSystemPrompt($type, $options)
    {
        $defaultPrompts = [
            'summary' => "Generate a concise summary of the given text in {{LANGUAGE}}, maximum length {{MAX_LENGTH}} characters.",
            'tags' => "Generate up to {{MAX_TAGS}} tags in {{LANGUAGE}}, separated by commas.",
            'category' => "Select the most appropriate category from {{CATEGORIES}} for the given content.",
            'seo' => "Generate SEO meta description (max {{SEO_LENGTH}} chars) and keywords in {{LANGUAGE}}."
        ];

        $prompt = '';
        $targetLang = self::getTargetLanguage($options->currentContent ?? '');

        $langMap = [
            'zh' => '中文',
            'en' => 'English',
            'ja' => '日本語',
            'ko' => '한국어',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'ru' => 'Русский'
        ];

        $language = $langMap[$targetLang] ?? 'English';

        switch ($type) {
            case 'summary':
                $prompt = $options->summaryPrompt ?: $defaultPrompts['summary'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{MAX_LENGTH}}'],
                    [$language, $options->maxLength],
                    $prompt);
                break;

            case 'tags':
                $prompt = $options->tagsPrompt ?: $defaultPrompts['tags'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{MAX_TAGS}}'],
                    [$language, $options->maxTags],
                    $prompt);
                break;

            case 'category':
                $prompt = $options->categoryPrompt ?: $defaultPrompts['category'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{CATEGORIES}}'],
                    [$language, implode("\n", $options->categories ?? [])],
                    $prompt);
                break;

            case 'seo':
                $prompt = $options->seoPrompt ?: $defaultPrompts['seo'];
                $prompt = str_replace(['{{LANGUAGE}}', '{{SEO_LENGTH}}'],
                    [$language, $options->seoLength ?? 200],
                    $prompt);
                break;
        }

        return $prompt;
    }

    private static function getOrGenerateSEOInfo($archive)
    {
        return self::handleCache($archive->cid, 'seo', function() use ($archive) {
            try {
                $response = self::callApi($archive->text, 'seo');
                $seoData = json_decode($response, true);

                // 验证返回的数据格式是否正确
                if (!is_array($seoData) || !isset($seoData['description']) || !isset($seoData['keywords'])) {
                    throw new Exception('SEO数据格式无效');
                }

                return $seoData;

            } catch (Exception $e) {
                self::handleError('SEO生成', $e);
                // 返回一个格式正确的备用数组
                return array(
                    'description' => mb_substr($archive->excerpt ?? '', 0, 200),
                    'keywords' => is_array($archive->tags) ? implode(',', $archive->tags) : ($archive->tags ?? '')
                );
            }
        });
    }

    private static function generateTags($content)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            $response = self::callApi($content, 'tags');

            $tags = array_map('trim', explode(',', $response));
            $tags = array_filter($tags, function($tag) {
                return !empty($tag) && mb_strlen($tag) <= 20;
            });

            $tags = array_unique($tags);
            $tags = array_slice($tags, 0, intval($options->maxTags));

            return implode(',', $tags);
        } catch (Exception $e) {
            error_log('标签生成错误: ' . $e->getMessage());
            return '';
        }
    }

    private static function suggestCategory($content)
    {
        try {
            $db = Typecho_Db::get();
            $categories = $db->fetchAll($db->select('mid, name, description')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', Typecho_Db::SORT_ASC));

            if (empty($categories)) {
                return '';
            }

            $categoryInfo = array();
            $categoryMap = array();
            foreach ($categories as $cat) {
                $categoryInfo[] = $cat['name'];
                $categoryMap[strtolower($cat['name'])] = $cat['mid'];
            }

            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            $options->categories = $categoryInfo;

            // 获取AI建议的分类
            $suggestedCategory = trim(self::callApi($content, 'category'));

            // 精确匹配
            foreach ($categories as $cat) {
                if (strcasecmp($cat['name'], $suggestedCategory) === 0) {
                    error_log('找到精确匹配的分类: ' . $cat['name'] . ' (mid: ' . $cat['mid'] . ')');
                    return $cat['mid'];
                }
            }

            // 如果没有精确匹配，尝试模糊匹配
            foreach ($categories as $cat) {
                if (mb_stripos($cat['name'], $suggestedCategory) !== false ||
                    mb_stripos($suggestedCategory, $cat['name']) !== false) {
                    error_log('找到模糊匹配的分类: ' . $cat['name'] . ' (mid: ' . $cat['mid'] . ')');
                    return $cat['mid'];
                }
            }

            // 如果还是没找到，使用默认分类
            $defaultCategory = $options->defaultCategory;
            foreach ($categories as $cat) {
                if ($cat['name'] === $defaultCategory) {
                    error_log('使用默认分类: ' . $cat['name'] . ' (mid: ' . $cat['mid'] . ')');
                    return $cat['mid'];
                }
            }

            // 如果连默认分类都没找到，使用第一个分类
            error_log('未找到匹配的分类，AI建议: ' . $suggestedCategory . '，使用第一个分类: ' . $categories[0]['name']);
            return $categories[0]['mid'];

        } catch (Exception $e) {
            error_log('分类推荐错误: ' . $e->getMessage());
            return '';
        }
    }

    private static function optimizeFormat($content)
    {
        try {
            return self::callApi($content, 'format');
        } catch (Exception $e) {
            self::handleError('Format Optimization', $e);
            return $content;
        }
    }

    private static function generateOutline($content)
    {
        try {
            return self::callApi($content, 'outline');
        } catch (Exception $e) {
            self::handleError('Outline Generation', $e);
            return '';
        }
    }

    private static function getTargetLanguage($content)
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');

        if ($options->language === 'auto') {
            return self::detectLanguage($content);
        }

        return $options->language;
    }

    private static function detectLanguage($content)
    {
        $sample = mb_substr($content, 0, 100);

        $zhCount = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $sample, $matches);
        $jaCount = preg_match_all('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sample, $matches);
        $koCount = preg_match_all('/[\x{3130}-\x{318F}\x{AC00}-\x{D7AF}]/u', $sample, $matches);
        $ruCount = preg_match_all('/[\x{0400}-\x{04FF}]/u', $sample, $matches);

        $total = mb_strlen($sample);

        if ($zhCount / $total > 0.3) return 'zh';
        if ($jaCount / $total > 0.3) return 'ja';
        if ($koCount / $total > 0.3) return 'ko';
        if ($ruCount / $total > 0.3) return 'ru';

        return 'en';
    }

    public static function cleanGarbage()
    {
        if (!Typecho_Widget::widget('Widget_User')->hasLogin()) {
            header('HTTP/1.1 403 Forbidden');
            exit(json_encode(['success' => false, 'message' => '未登录']));
        }

        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIBaTgrMX');
            $cleanOptions = $options->cleanOptions;
            $results = array();
            $totalCleaned = 0;

            if (in_array('cache', $cleanOptions)) {
                $cacheDir = __TYPECHO_ROOT_DIR__ . '/usr/cache/';
                $cleaned = self::cleanDirectory($cacheDir);
                $results[] = "清理缓存文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('temp', $cleanOptions)) {
                $tempDir = __TYPECHO_ROOT_DIR__ . '/usr/temp/';
                $cleaned = self::cleanDirectory($tempDir);
                $results[] = "清理临时文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('draft', $cleanOptions)) {
                $db = Typecho_Db::get();
                $drafts = $db->query($db->delete('table.contents')
                    ->where('type = ?', 'post_draft')
                    ->where('modified < ?', time() - 30 * 86400));
                $results[] = "清理草稿：{$drafts}篇";
                $totalCleaned += $drafts;
            }

            if (in_array('spam', $cleanOptions)) {
                $db = Typecho_Db::get();
                $spams = $db->query($db->delete('table.comments')
                    ->where('status = ?', 'spam')
                    ->where('created < ?', time() - 7 * 86400));
                $results[] = "清理垃圾评论：{$spams}条";
                $totalCleaned += $spams;
            }

            if (in_array('upload', $cleanOptions)) {
                $cleaned = self::cleanUnusedUploads();
                $results[] = "清理未引用文件：{$cleaned}个";
                $totalCleaned += $cleaned;
            }

            if (in_array('ai_cache', $cleanOptions)) {
                $db = Typecho_Db::get();
                $aiCaches = $db->query($db->delete('table.ai_content')
                    ->where('created < ?', time() - $options->cacheTime));
                $results[] = "清理AI缓存：{$aiCaches}条";
                $totalCleaned += $aiCaches;
            }

            Helper::options()->plugin('AIBaTgrMX')->lastCleanTime = time();

            $message = implode("\n", $results);
            exit(json_encode([
                'success' => true,
                'message' => $message,
                'total' => $totalCleaned
            ]));

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            exit(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }
    }

    private static function cleanDirectory($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $files = glob($dir . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                $count += self::cleanDirectory($file);
                @rmdir($file);
            } else {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function cleanUnusedUploads()
    {
        $db = Typecho_Db::get();
        $uploadDir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/';
        $count = 0;

        $contents = $db->fetchAll($db->select('text, content')
            ->from('table.contents')
            ->where('type = ? OR type = ?', 'post', 'page'));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $usedFiles = array();
        foreach ($contents as $content) {
            preg_match_all('/\/usr\/uploads\/([^\s\'"<>)}\]]+)/', $content['text'] . $content['content'], $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $file) {
                    $usedFiles[$file] = true;
                }
            }
        }

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($uploadDir, '', $file->getPathname());
                if (!isset($usedFiles[$relativePath])) {
                    if (@unlink($file->getPathname())) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private static function warmupCache($content, $type)
    {
        try {
            $db = Typecho_Db::get();
            $cacheKey = md5($content . $type);

            $cached = $db->fetchRow($db->select()
                ->from('table.ai_content')
                ->where('type = ?', $type)
                ->where('cid = ?', $cacheKey));

            if (!$cached) {
                self::asyncApiCall($content, $type);
            }
        } catch (Exception $e) {
            self::handleError('Cache Warmup', $e);
        }
    }

    private static function asyncApiCall($content, $type)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $url = Typecho_Common::url('async-ai', $options->index);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'content' => $content,
                'type' => $type
            )),
            CURLOPT_NOSIGNAL => 1
        ));
        curl_exec($ch);
        curl_close($ch);
    }

    private static function securityCheck($content)
    {
        $content = self::xssClean($content);

        if (self::containsSensitiveContent($content)) {
            throw new Exception('Content contains sensitive information');
        }

        if (!self::checkRateLimit()) {
            throw new Exception('API rate limit exceeded');
        }

        return $content;
    }

    private static function xssClean($data)
    {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    private static function trackApiUsage($type, $responseTime)
    {
        $db = Typecho_Db::get();
        $db->query($db->insert('table.ai_stats')->rows(array(
            'type' => $type,
            'response_time' => $responseTime,
            'created' => time()
        )));
    }

    private static function backupBeforeChange($content)
    {
        $backupDir = __TYPECHO_ROOT_DIR__ . '/usr/backup/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . date('Ymd_His') . '.json';
        file_put_contents($backupFile, json_encode(array(
            'content' => $content,
            'timestamp' => time()
        )));
    }
}