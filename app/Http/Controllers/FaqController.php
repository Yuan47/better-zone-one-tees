<?php

namespace App\Http\Controllers;

use App\Models\ChatbotFaq;
use App\Services\ActivityRecorder;
use App\Services\StoreKnowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FaqController extends Controller
{
    public function create()
    {
        return view('admin.faq', ['faq' => new ChatbotFaq(['active' => true, 'sort_order' => 0, 'audience' => 'customer'])]);
    }

    public function store(Request $r)
    {
        $data = $r->validate($this->rules());
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $faq = ChatbotFaq::create($data);
        ActivityRecorder::mark($r, 'faq.created', $faq, ['fields' => ['question', 'answer', 'keywords', 'active', 'sort_order', 'audience']]);

        return redirect()->route('admin.settings')->with('notice', 'FAQ added. Active answers are available to the selected audience.');
    }

    public function edit(ChatbotFaq $faq)
    {
        return view('admin.faq', compact('faq'));
    }

    public function update(Request $r, ChatbotFaq $faq)
    {
        $data = $r->validate($this->rules($faq));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $faq->fill($data);
        $changed = array_keys($faq->getDirty());
        if ($changed !== []) {
            $faq->save();
            ActivityRecorder::mark($r, 'faq.updated', $faq, ['fields' => $changed]);
        }

        return redirect()->route('admin.settings')->with('notice', $changed === [] ? 'No FAQ changes to save.' : 'FAQ updated.');
    }

    public function destroy(Request $r, ChatbotFaq $faq)
    {
        if ($faq->delete()) {
            ActivityRecorder::mark($r, 'faq.deleted', $faq, ['fields' => ['question', 'answer', 'keywords', 'active', 'sort_order', 'audience']]);
        }

        return redirect()->route('admin.settings')->with('notice', 'FAQ deleted. Its answer is no longer available to the assistant.');
    }

    public function import(Request $r)
    {
        $r->validate(['faq_csv' => 'required|file|mimes:csv,txt|max:1024']);
        $file = $r->file('faq_csv');
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['faq_csv' => 'Choose a CSV file using the downloadable template.']);
        }
        $stream = fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['faq_csv' => 'The CSV file could not be read.']);
        }
        $rows = [];
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if ($headers === false) {
                throw ValidationException::withMessages(['faq_csv' => 'The CSV file is empty.']);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0] ?? '');
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
            if (! in_array('question', $headers, true) || ! in_array('answer', $headers, true)
                || count(array_unique($headers)) !== count($headers)
                || array_diff($headers, ['question', 'answer', 'keywords', 'active', 'sort_order', 'audience']) !== []) {
                throw ValidationException::withMessages(['faq_csv' => 'Use question and answer headers. Optional headers: keywords, active, sort_order, audience.']);
            }
            $seen = [];
            $line = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $line++;
                if (array_filter($cells, fn ($cell) => trim((string) $cell) !== '') === []) {
                    continue;
                }
                if (count($cells) !== count($headers)) {
                    throw ValidationException::withMessages(['faq_csv' => 'CSV row '.$line.' has the wrong number of columns.']);
                }
                if (count($rows) >= 100) {
                    throw ValidationException::withMessages(['faq_csv' => 'Import up to 100 FAQ entries at a time.']);
                }
                $row = array_map(fn ($value) => trim((string) $value), array_combine($headers, $cells));
                $active = strtolower($row['active'] ?? '1');
                if (! in_array($active, ['', '1', 'true', 'yes', 'active', '0', 'false', 'no', 'inactive'], true)) {
                    throw ValidationException::withMessages(['faq_csv' => 'CSV row '.$line.': active must be 1 or 0 (true or false).']);
                }
                $row['active'] = ! in_array($active, ['0', 'false', 'no', 'inactive'], true);
                $row['sort_order'] = ($row['sort_order'] ?? '') === '' ? 0 : $row['sort_order'];
                $row['keywords'] = ($row['keywords'] ?? '') === '' ? null : $row['keywords'];
                $row['audience'] = ($row['audience'] ?? '') === '' ? 'customer' : $row['audience'];
                $validator = Validator::make($row, $this->rules());
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['faq_csv' => 'CSV row '.$line.': '.implode(' ', $validator->errors()->all())]);
                }
                $key = mb_strtolower($row['question']);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['faq_csv' => 'CSV row '.$line.' repeats a question in this file.']);
                }
                $seen[$key] = true;
                $rows[] = $validator->validated();
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['faq_csv' => 'Add at least one FAQ entry below the header row.']);
        }
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                ChatbotFaq::create($row);
            }
        });
        ActivityRecorder::mark($r, 'faq.imported', null, ['imported_count' => count($rows)]);

        return redirect()->route('admin.settings')->with('notice', count($rows).' FAQ entries imported. Active answers are available only to their selected audience.');
    }

    public function template()
    {
        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['question', 'answer', 'keywords', 'active', 'sort_order', 'audience'], ',', '"', '');
            fputcsv($stream, ['Where can I see product measurements?', StoreKnowledge::SIZE_HELP, 'size chart, measurements', '1', '0', 'customer'], ',', '"', '');
            fclose($stream);
        }, 'zone-one-faq-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rules(?ChatbotFaq $faq = null): array
    {
        return [
            'question' => ['required', 'string', 'max:250', Rule::unique('chatbot_faqs', 'question')->ignore($faq?->id)],
            'answer' => 'required|string|max:2500', 'keywords' => 'nullable|string|max:500',
            'active' => 'required|boolean', 'sort_order' => 'nullable|integer|min:0|max:100000',
            'audience' => ['sometimes', 'required', Rule::in(ChatbotFaq::AUDIENCES)],
        ];
    }
}
