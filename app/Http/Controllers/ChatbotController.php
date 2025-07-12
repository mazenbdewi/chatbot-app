<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UniversityInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotApiController extends Controller
{
    public function index()
    {
        return response()->json('hiii');
    }

    public function chat(Request $request)
    {
        return response()->json('hiii');
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $question = $request->input('message');

        // تحديد لغة السؤال
        $language = $this->detectLanguage($question);

        $words = preg_split('/\s+/', $question);

        // البحث عن معلومات ذات صلة في قاعدة البيانات
        $relevantInfo = UniversityInfo::where(function ($query) use ($words) {
            foreach ($words as $word) {
                $query->orWhere('title', 'LIKE', "%$word%")
                    ->orWhere('content', 'LIKE', "%$word%");
            }
        })->pluck('content')->implode("\n");

        if (empty($relevantInfo)) {
            $relevantInfo = 'لا توجد معلومات محددة في قاعدة البيانات، أجب بأفضل ما لديك.';
        }

        // بناء الـ prompt
        $prompt = "أنت مساعد افتراضي خاص بجامعة المستقبل.\n".
                  "إليك معلومات الجامعة:\n".$relevantInfo."\n\n".
                  'سؤال الطالب: '.$question."\n".
                  "أجب على السؤال باستخدام نفس لغة الطالب وهي: {$language}.";

        // الاتصال بـ Ollama
        try {
            $response = Http::timeout(60)->post('http://127.0.0.1:11434/api/generate', [
                'model' => 'llama3',
                'prompt' => $prompt,
                'stream' => false,
            ]);

            return response()->json([
                'response' => $response->json()['response'] ?? 'لم أتمكن من الفهم، حاول مجددًا.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'response' => 'تعذر الاتصال بالنموذج: '.$e->getMessage(),
            ], 500);
        }
    }

    private function detectLanguage(string $text): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'العربية';
        }

        return 'الإنجليزية';
    }
}
