<div class="mb-3">
    <label for="title" class="form-label">タイトル</label>
    <input type="text" class="form-control" id="title" name="title"
           value="{{ old('title', $task->title ?? '') }}">
    @error('title')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">詳細</label>
    <textarea class="form-control" id="description" name="description" rows="6">{{ old('description', $task->description ?? '') }}</textarea>
    @error('description')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="due_date" class="form-label">期限（任意）</label>
    <input type="date" class="form-control" id="due_date" name="due_date"
           value="{{ old('due_date', isset($task->due_date) ? $task->due_date->format('Y-m-d') : '') }}">
    @error('due_date')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<button type="submit" class="btn btn-primary">{{ $submitLabel ?? '保存' }}</button>
