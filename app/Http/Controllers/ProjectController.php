<?php

namespace App\Http\Controllers;

use App\Models\UniversityInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    const MAX_HISTORY_LENGTH = 10;

    public function index()
    {
        return response()->json(['message' => 'مرحباً بك في chatbot جامعة اللاذقية']);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'reset' => 'sometimes|boolean',
        ]);

        if ($request->input('reset', false)) {
            session()->forget('chat_history');

            return response()->json(['response' => 'تم إعادة تعيين المحادثة']);
        }

        $userMessage = trim($request->input('message'));
        $chatHistory = session('chat_history', []);
        $language = $this->detectLanguage($userMessage);

        $relevantInfo = $this->getRelevantUniversityInfo($userMessage);
        $hasRelevantInfo = ! empty($relevantInfo);

        Log::debug('User message: '.$userMessage);
        Log::debug('Relevant info found: '.($hasRelevantInfo ? 'YES' : 'NO'));

        $prompt = $this->buildChatPrompt($userMessage, $chatHistory, $relevantInfo, $language);

        try {
            $botResponse = $this->getAIResponse($prompt);
            $finalResponse = $this->processFinalResponse($botResponse, $hasRelevantInfo, $userMessage);

            $chatHistory[] = ['user' => $userMessage, 'bot' => $finalResponse];
            if (count($chatHistory) > self::MAX_HISTORY_LENGTH) {
                array_shift($chatHistory);
            }
            session(['chat_history' => $chatHistory]);

            return response()->json([
                'response' => $finalResponse,
                'history' => $chatHistory,
            ]);
        } catch (\Exception $e) {
            Log::error('AI API Error: '.$e->getMessage());

            return response()->json([
                'response' => 'عذراً، حدث خطأ أثناء معالجة طلبك. يرجى المحاولة مرة أخرى.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getRelevantUniversityInfo(string $question): string
    {
        $keywords = $this->extractKeywords($question);

        if (empty($keywords)) {
            return '';
        }

        $query = UniversityInfo::query();
        $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('content', 'LIKE', "%{$keyword}%");
            }
        });

        $results = $query->limit(3)->get();

        $infoText = '';
        foreach ($results as $result) {
            $infoText .= "### {$result->title} ###\n{$result->content}\n\n";
        }

        return trim($infoText);
    }

    private function extractKeywords(string $text): array
    {
        $text = $this->normalizeArabic($text);

        $dbKeywords = UniversityInfo::pluck('title')->toArray(); // جميع العناوين كمصفوفة

        $found = [];

        foreach ($dbKeywords as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                $found[] = $keyword;
            }
        }

        return array_unique($found);
    }

    private function normalizeArabic(string $text): string
    {
        $replacements = [
            '/[أإآ]/u' => 'ا',
            '/ة/' => 'ه',
            '/[يى]/u' => 'ي',
            '/[ؤئ]/u' => 'ء',
        ];

        $normalized = preg_replace(array_keys($replacements), array_values($replacements), $text);

        return preg_replace('/[^\p{Arabic}\p{N}\s]/u', '', $normalized);
    }

    private function buildChatPrompt(string $userMessage, array $chatHistory, string $relevantInfo, string $language): string
    {
        $prompt = "أنت مساعد ذكي لجامعة اللاذقية.\n"
            ."1. التزم بالإجابة باستخدام المعلومات التالية فقط.\n"
            ."2. لا تعتذر إذا كانت المعلومات متوفرة.\n"
            ."3. الردود يجب أن تكون قصيرة ودقيقة (2-3 جمل).\n"
            ."4. اللغة المطلوبة: {$language}\n\n";

        if (! empty($relevantInfo)) {
            $prompt .= "--- معلومات الجامعة ---\n{$relevantInfo}\n--- نهاية المعلومات ---\n\n";
        } else {
            $prompt .= "--- لا توجد معلومات متاحة ---\n\n";
        }

        $recentHistory = array_slice($chatHistory, -2);
        foreach ($recentHistory as $entry) {
            $prompt .= "الطالب: {$entry['user']}\n";
            $prompt .= "المساعد: {$entry['bot']}\n";
        }

        $prompt .= "الطالب: {$userMessage}\n";
        $prompt .= 'المساعد:';

        return $prompt;
    }

    private function getAIResponse(string $prompt): string
    {
        $response = Http::timeout(90)->post('http://91.144.21.215:11434/api/generate', [
            'model' => 'iKhalid/ALLaM:7b', // iKhalid/ALLaM:7b  llama3.1:8b
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.5, // 0.2
                'max_tokens' => 600, // 300
                // 'presence_penalty' => 0.2,
                // 'frequency_penalty' => 0.2,
            ],
        ]);

        if (! $response->successful()) {
            throw new \Exception('فشل الاتصال بنموذج الذكاء الاصطناعي: '.$response->status());
        }

        return $response->json()['response'] ?? 'عذراً، لم أتمكن من فهم سؤالك.';
    }

    private function processFinalResponse(string $response, bool $hasRelevantInfo, string $userQuestion): string
    {
        $response = preg_replace('/أنا (نموذج|مساعد ذكي|AI|ذكاء اصطناعي)/i', '', $response);

        if ($hasRelevantInfo && preg_match('/عذراً|لا أملك|لا أعرف|ليس لدي|خارج نطاق/i', $response)) {
            return $this->generateDirectResponse($userQuestion);
        }

        $sentences = preg_split('/(؟|\.|!)\s+/u', $response, 3, PREG_SPLIT_NO_EMPTY);

        return implode(' ', array_slice($sentences, 0, 2));
    }

    private function generateDirectResponse(string $question): string
    {
        if (stripos($question, 'تسجيل') !== false) {
            return 'يمكنك التسجيل عبر موقع الجامعة الرسمي. المستندات المطلوبة: شهادة الثانوية، صورة الهوية، ودفع الرسوم.';
        }

        $commonResponses = [
            'رسوم' => 'رسوم التسجيل تبلغ 500 ريال لكل فصل دراسي.',
            'مواعيد' => 'مواعيد التسجيل تبدأ في 1 سبتمبر وتنتهي في 15 أكتوبر.',
            'شروط' => 'شروط القبول تشمل: شهادة الثانوية واجتياز المقابلة الشخصية.',
            'مستندات' => 'المستندات المطلوبة: صورة الهوية، شهادة الثانوية، 4 صور شخصية.',
        ];

        foreach ($commonResponses as $keyword => $resp) {
            if (stripos($question, $keyword) !== false) {
                return $resp;
            }
        }

        return 'للاستفسار عن معلومات الجامعة، يرجى زيارة موقع الجامعة أو التواصل مع قسم القبول.';
    }

    private function detectLanguage(string $text): string
    {
        return (preg_match('/[ا-ي]/u', $text)) ? 'العربية' : 'الإنجليزية';
    }
}
