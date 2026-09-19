@props(['subjectLine' => 'NavlunIQ', 'preheader' => '', 'message' => null])
@include('emails.layouts.base', ['slot' => $slot, 'subjectLine' => $subjectLine, 'preheader' => $preheader, 'message' => $message])
