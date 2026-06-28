<?php

namespace App\Services;

/**
 * PHP-only intent parser — no AI, no API calls.
 * Extracts structured search signals from natural-language Vietnamese book queries.
 */
class IntentParserService
{
    // Normalized (no-diacritic, lowercase) trigger → expanded search terms
    private const DICT = [
        // Leadership / Management
        'lanh dao'             => ['lãnh đạo', 'leadership', 'quản trị', 'quản lý', 'management'],
        'quan tri'             => ['quản trị', 'lãnh đạo', 'management', 'leadership'],
        'quan ly'              => ['quản lý', 'quản trị', 'management'],
        'nhan su'              => ['nhân sự', 'HR', 'quản lý nhân sự', 'human resource'],

        // Communication / Soft Skills
        'giao tiep'            => ['giao tiếp', 'communication', 'kỹ năng mềm', 'soft skill'],
        'ky nang mem'          => ['kỹ năng mềm', 'soft skill', 'giao tiếp', 'communication'],
        'ky nang song'         => ['kỹ năng sống', 'life skill', 'phát triển bản thân'],
        'ky nang'              => ['kỹ năng', 'skill', 'phát triển bản thân'],
        'phat trien ban than'  => ['phát triển bản thân', 'self-development', 'kỹ năng'],
        'tu duy'               => ['tư duy', 'mindset', 'thinking', 'critical thinking'],
        'sang tao'             => ['sáng tạo', 'creativity', 'innovation', 'design thinking'],

        // Programming
        'lap trinh'            => ['lập trình', 'programming', 'code', 'phần mềm'],
        'python'               => ['Python', 'lập trình', 'programming', 'code'],
        'javascript'           => ['JavaScript', 'lập trình web', 'frontend', 'programming'],
        'typescript'           => ['TypeScript', 'JavaScript', 'lập trình web', 'programming'],
        'java'                 => ['Java', 'lập trình', 'backend', 'programming'],
        'c++'                  => ['C++', 'lập trình', 'programming', 'systems'],
        'golang'               => ['Go', 'Golang', 'lập trình', 'programming', 'backend'],
        'rust'                 => ['Rust', 'lập trình hệ thống', 'programming'],
        'php'                  => ['PHP', 'lập trình web', 'backend', 'programming'],
        'phan mem'             => ['phần mềm', 'software', 'lập trình', 'programming'],
        'thuat toan'           => ['thuật toán', 'algorithm', 'data structure', 'cấu trúc dữ liệu'],
        'cau truc du lieu'     => ['cấu trúc dữ liệu', 'data structure', 'algorithm', 'thuật toán'],

        // AI / ML / Data
        'machine learning'     => ['machine learning', 'AI', 'trí tuệ nhân tạo', 'deep learning', 'data science'],
        'deep learning'        => ['deep learning', 'machine learning', 'AI', 'neural network'],
        'tri tue nhan tao'     => ['trí tuệ nhân tạo', 'AI', 'machine learning', 'artificial intelligence'],
        'data science'         => ['data science', 'machine learning', 'AI', 'phân tích dữ liệu'],
        'phan tich du lieu'    => ['phân tích dữ liệu', 'data analysis', 'data science', 'statistics'],

        // Finance / Investment
        'tai chinh'            => ['tài chính', 'finance', 'đầu tư', 'investment', 'kinh tế'],
        'dau tu'               => ['đầu tư', 'investment', 'tài chính', 'chứng khoán'],
        'chung khoan'          => ['chứng khoán', 'stock market', 'đầu tư', 'investment'],
        'bat dong san'         => ['bất động sản', 'real estate', 'đầu tư', 'investment'],
        'ke toan'              => ['kế toán', 'accounting', 'tài chính', 'finance'],
        'cha giau cha ngheo'   => ['tài chính cá nhân', 'Rich Dad Poor Dad', 'đầu tư', 'tài chính'],

        // Business / Entrepreneurship
        'kinh doanh'           => ['kinh doanh', 'business', 'doanh nghiệp', 'startup'],
        'startup'              => ['startup', 'khởi nghiệp', 'kinh doanh', 'entrepreneur'],
        'khoi nghiep'          => ['khởi nghiệp', 'startup', 'kinh doanh'],
        'doanh nghiep'         => ['doanh nghiệp', 'business', 'kinh doanh', 'enterprise'],
        'marketing'            => ['marketing', 'tiếp thị', 'thương hiệu', 'brand'],
        'ban hang'             => ['bán hàng', 'sales', 'kinh doanh', 'business'],

        // Medical / Health
        'bac si'               => ['y khoa', 'y học', 'medical', 'lâm sàng', 'bác sĩ'],
        'y khoa'               => ['y khoa', 'y học', 'medical', 'lâm sàng'],
        'y hoc'                => ['y học', 'y khoa', 'medical', 'sức khỏe'],
        'suc khoe'             => ['sức khỏe', 'health', 'y tế', 'wellbeing'],
        'dieu duong'           => ['điều dưỡng', 'nursing', 'y khoa', 'medical'],
        'duoc hoc'             => ['dược học', 'pharmacy', 'y khoa', 'thuốc'],
        'dinh duong'           => ['dinh dưỡng', 'nutrition', 'sức khỏe', 'health'],

        // Psychology
        'tam ly'               => ['tâm lý', 'psychology', 'mindset', 'hành vi'],
        'tam ly hoc'           => ['tâm lý học', 'psychology', 'behavioral science'],
        'cam xuc'              => ['cảm xúc', 'emotion', 'tâm lý', 'EQ', 'emotional intelligence'],

        // Children / Education
        'tre em'               => ['thiếu nhi', 'trẻ em', 'children', 'sách thiếu nhi'],
        'thieu nhi'            => ['thiếu nhi', 'trẻ em', 'children', 'sách thiếu nhi'],
        'hoc sinh'             => ['học sinh', 'giáo dục', 'education', 'học đường'],
        'sinh vien'            => ['sinh viên', 'đại học', 'university', 'academic'],
        'giao duc'             => ['giáo dục', 'education', 'học tập', 'learning'],
        'phu huynh'            => ['phụ huynh', 'parenting', 'nuôi dạy con', 'gia đình'],

        // DevOps / Cloud
        'kubernetes'           => ['Kubernetes', 'DevOps', 'container', 'Docker', 'cloud'],
        'docker'               => ['Docker', 'container', 'DevOps', 'Kubernetes', 'cloud'],
        'devops'               => ['DevOps', 'Kubernetes', 'Docker', 'cloud', 'CI/CD'],
        'cloud'                => ['cloud', 'điện toán đám mây', 'AWS', 'Azure', 'GCP', 'DevOps'],
        'aws'                  => ['AWS', 'Amazon Web Services', 'cloud', 'điện toán đám mây'],
        'microservices'        => ['microservices', 'kiến trúc phần mềm', 'cloud', 'DevOps'],

        // Security / Networking
        'an ninh mang'         => ['an ninh mạng', 'cybersecurity', 'bảo mật', 'security'],
        'bao mat'              => ['bảo mật', 'security', 'an ninh mạng', 'cybersecurity'],
        'mang may tinh'        => ['mạng máy tính', 'network', 'networking', 'TCP/IP'],

        // Database
        'co so du lieu'        => ['cơ sở dữ liệu', 'database', 'SQL', 'MySQL', 'NoSQL'],
        'sql'                  => ['SQL', 'cơ sở dữ liệu', 'database', 'MySQL'],

        // Literature
        'van hoc'              => ['văn học', 'literature', 'tiểu thuyết', 'truyện'],
        'tieu thuyet'          => ['tiểu thuyết', 'novel', 'văn học', 'fiction'],
        'truyen ngan'          => ['truyện ngắn', 'short story', 'văn học'],
        'truyen tranh'         => ['truyện tranh', 'comic', 'manga'],
        'lich su'              => ['lịch sử', 'history', 'historical', 'lịch sử Việt Nam'],
        'truyen ky'            => ['truyện ký', 'memoir', 'hồi ký', 'biography'],
        'tho'                  => ['thơ', 'poetry', 'văn học'],

        // Science / Mathematics
        'khoa hoc'             => ['khoa học', 'science', 'nghiên cứu', 'research'],
        'toan hoc'             => ['toán học', 'mathematics', 'math', 'đại số', 'giải tích'],
        'vat ly'               => ['vật lý', 'physics', 'khoa học'],
        'hoa hoc'              => ['hóa học', 'chemistry', 'khoa học'],
        'sinh hoc'             => ['sinh học', 'biology', 'khoa học'],

        // Language Learning
        'ngoai ngu'            => ['ngoại ngữ', 'language learning', 'tiếng Anh', 'english'],
        'tieng anh'            => ['tiếng Anh', 'English', 'ngoại ngữ', 'IELTS', 'TOEIC'],
        'tieng nhat'           => ['tiếng Nhật', 'Japanese', 'ngoại ngữ'],
        'tieng trung'          => ['tiếng Trung', 'Chinese', 'ngoại ngữ'],
        'tieng han'            => ['tiếng Hàn', 'Korean', 'ngoại ngữ'],

        // Spirituality / Philosophy
        'ton giao'             => ['tôn giáo', 'religion', 'triết học', 'philosophy'],
        'triet hoc'            => ['triết học', 'philosophy', 'tư duy', 'mindset'],
        'ky nang song'         => ['kỹ năng sống', 'life skill', 'phát triển bản thân'],
    ];

    // Normalized → display difficulty
    private const DIFFICULTY = [
        'nhap mon'    => 'nhập môn',
        'co ban'      => 'cơ bản',
        'trung cap'   => 'trung cấp',
        'nang cao'    => 'nâng cao',
        'chuyen sau'  => 'chuyên sâu',
        'moi bat dau' => 'nhập môn',
        'beginner'    => 'nhập môn',
        'basic'       => 'cơ bản',
        'intermediate'=> 'trung cấp',
        'advanced'    => 'nâng cao',
        'expert'      => 'chuyên sâu',
    ];

    // Normalized → display target reader
    private const TARGET_READER = [
        'tre em'           => 'trẻ em',
        'thieu nhi'        => 'thiếu nhi',
        'hoc sinh'         => 'học sinh',
        'sinh vien'        => 'sinh viên',
        'nguoi di lam'     => 'người đi làm',
        'nguoi moi di lam' => 'người mới đi làm',
        'nguoi moi'        => 'người mới',
        'chuyen gia'       => 'chuyên gia',
        'ky su'            => 'kỹ sư',
        'lap trinh vien'   => 'lập trình viên',
        'bac si'           => 'bác sĩ',
        'giao vien'        => 'giáo viên',
        'quan ly'          => 'quản lý',
        'doanh nhan'       => 'doanh nhân',
        'nguoi gia'        => 'người cao tuổi',
        'phu huynh'        => 'phụ huynh',
    ];

    // Normalized → display author
    private const AUTHORS = [
        'nguyen nhat anh'   => 'Nguyễn Nhật Ánh',
        'nam cao'           => 'Nam Cao',
        'to hoai'           => 'Tô Hoài',
        'nguyen du'         => 'Nguyễn Du',
        'bao ninh'          => 'Bảo Ninh',
        'nguyen huy thiep'  => 'Nguyễn Huy Thiệp',
        'dale carnegie'     => 'Dale Carnegie',
        'napoleon hill'     => 'Napoleon Hill',
        'robert kiyosaki'   => 'Robert Kiyosaki',
        'paulo coelho'      => 'Paulo Coelho',
        'haruki murakami'   => 'Haruki Murakami',
        'stephen king'      => 'Stephen King',
        'daniel kahneman'   => 'Daniel Kahneman',
        'malcolm gladwell'  => 'Malcolm Gladwell',
        'nguyen ngoc tu'    => 'Nguyễn Ngọc Tư',
        'ho anh thai'       => 'Hồ Anh Thái',
        'le luu'            => 'Lê Lựu',
        'vu trong phung'    => 'Vũ Trọng Phụng',
        'james clear'       => 'James Clear',
        'yuval noah harari' => 'Yuval Noah Harari',
    ];

    // Normalized known book title → display title
    private const KNOWN_BOOKS = [
        'dac nhan tam'                    => 'Đắc Nhân Tâm',
        'nha gia kim'                     => 'Nhà Giả Kim',
        'mat biec'                        => 'Mắt Biếc',
        'cho toi xin mot ve di tuoi tho'  => 'Cho Tôi Xin Một Vé Đi Tuổi Thơ',
        'chi pheo'                        => 'Chí Phèo',
        'lao hac'                         => 'Lão Hạc',
        'truyen kieu'                     => 'Truyện Kiều',
        'cha giau cha ngheo'              => 'Cha Giàu Cha Nghèo',
        'doi moi ban than'                => 'Đổi Mới Bản Thân',
        'so phan con nguoi'               => 'Số Phận Con Người',
        'atomic habits'                   => 'Atomic Habits',
        'thinking fast and slow'          => 'Thinking, Fast and Slow',
        'the alchemist'                   => 'The Alchemist',
        'sapiens'                         => 'Sapiens',
        'tuoi tre dang gia bao nhieu'     => 'Tuổi Trẻ Đáng Giá Bao Nhiêu',
        'song va cho di'                  => 'Sống Và Cho Đi',
        'nguoi ky la o thu vien'          => 'Người Kỳ Lạ Ở Thư Viện',
        'bo lao to kho'                   => 'Bố Lão Tố Khổ',
    ];

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse a natural-language message and return structured search signals.
     *
     * @return array{
     *   query: string|null,
     *   author: string|null,
     *   language: string|null,
     *   difficulty: string|null,
     *   target_reader: string|null,
     *   topic: string|null,
     *   category: string|null,
     *   keywords: list<string>
     * }
     */
    public function parse(string $message): array
    {
        $norm = $this->normalize($message);

        return [
            'query'         => $this->detectKnownBook($norm),
            'author'        => $this->detectAuthor($norm),
            'language'      => $this->detectLanguage($norm),
            'difficulty'    => $this->detectDifficulty($norm),
            'target_reader' => $this->detectTargetReader($norm),
            'topic'         => $this->detectTopic($norm),
            'category'      => $this->detectCategory($norm),
            'keywords'      => $this->expandKeywords($norm),
        ];
    }

    // ── Detection helpers ─────────────────────────────────────────────────────

    private function detectKnownBook(string $norm): ?string
    {
        foreach (self::KNOWN_BOOKS as $normTitle => $display) {
            if ($this->phraseIn($norm, $normTitle)) {
                return $display;
            }
        }
        return null;
    }

    private function detectAuthor(string $norm): ?string
    {
        // Longest-match first to avoid "nam cao" matching inside "nguyen huy thiep" etc.
        $sorted = self::AUTHORS;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $normName => $display) {
            if ($this->phraseIn($norm, $normName)) {
                return $display;
            }
        }
        return null;
    }

    private function detectLanguage(string $norm): ?string
    {
        $en = ['tieng anh', 'english', 'sach tieng anh', 'sach anh', 'bang tieng anh', 'ielts', 'toeic', 'toefl'];
        $vi = ['tieng viet', 'sach tieng viet', 'sach viet', 'vietnamese', 'bang tieng viet'];

        foreach ($en as $marker) {
            if ($this->phraseIn($norm, $marker)) {
                return 'en';
            }
        }
        foreach ($vi as $marker) {
            if ($this->phraseIn($norm, $marker)) {
                return 'vi';
            }
        }
        return null;
    }

    private function detectDifficulty(string $norm): ?string
    {
        $sorted = self::DIFFICULTY;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $normKey => $display) {
            if ($this->phraseIn($norm, $normKey)) {
                return $display;
            }
        }
        return null;
    }

    private function detectTargetReader(string $norm): ?string
    {
        $sorted = self::TARGET_READER;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $normKey => $display) {
            if ($this->phraseIn($norm, $normKey)) {
                return $display;
            }
        }
        return null;
    }

    private function detectTopic(string $norm): ?string
    {
        // Longest matching DICT key wins (more specific)
        $best       = null;
        $bestLen    = 0;

        foreach (self::DICT as $key => $terms) {
            if (strlen($key) > $bestLen && $this->phraseIn($norm, $key)) {
                $best    = $terms[0]; // first term is the canonical name
                $bestLen = strlen($key);
            }
        }

        return $best;
    }

    private function detectCategory(string $norm): ?string
    {
        $map = [
            'tieu thuyet'  => 'Tiểu thuyết',
            'truyen tranh' => 'Truyện tranh',
            'truyen ngan'  => 'Truyện ngắn',
            'van hoc'      => 'Văn học',
            'ky nang'      => 'Kỹ năng',
            'khoa hoc'     => 'Khoa học',
            'lich su'      => 'Lịch sử',
            'tam ly'       => 'Tâm lý',
            'kinh te'      => 'Kinh tế',
            'lap trinh'    => 'Lập trình',
            'thieu nhi'    => 'Thiếu nhi',
            'tre em'       => 'Thiếu nhi',
            'giao duc'     => 'Giáo dục',
            'y hoc'        => 'Y học',
            'y khoa'       => 'Y học',
            'nghe thuat'   => 'Nghệ thuật',
            'triet hoc'    => 'Triết học',
            'ton giao'     => 'Tôn giáo',
        ];

        $sorted = $map;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $normKey => $display) {
            if ($this->phraseIn($norm, $normKey)) {
                return $display;
            }
        }
        return null;
    }

    /**
     * Expand the message into a list of related search terms using DICT.
     * Returns deduplicated union of all matching entries.
     */
    public function expandKeywords(string $norm): array
    {
        $kw = [];
        foreach (self::DICT as $key => $terms) {
            if ($this->phraseIn($norm, $key)) {
                $kw = array_merge($kw, $terms);
            }
        }
        return array_values(array_unique($kw));
    }

    // ── String helpers ────────────────────────────────────────────────────────

    /**
     * Check if $needle appears as a whole phrase in $haystack.
     * Pads both sides of haystack with spaces so word-boundary checks work.
     */
    private function phraseIn(string $haystack, string $needle): bool
    {
        $padded = ' ' . $haystack . ' ';
        $search = ' ' . $needle . ' ';
        return str_contains($padded, $search);
    }

    /**
     * Convert Vietnamese diacritics to ASCII equivalents, lowercase,
     * and replace punctuation with spaces so word-boundary checks work
     * even when terms are followed by commas, question marks, etc.
     */
    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a',
            'ă'=>'a','ắ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
            'â'=>'a','ấ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
            'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
            'ê'=>'e','ế'=>'e','ề'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
            'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
            'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o',
            'ô'=>'o','ố'=>'o','ồ'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
            'ơ'=>'o','ớ'=>'o','ờ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
            'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
            'ư'=>'u','ứ'=>'u','ừ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
            'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
            'đ'=>'d',
        ]);
        // Replace punctuation/special chars with spaces, then collapse runs
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
