<div class="mb-3">
    <label for="title" class="form-label">タイトル</label>
    <input type="text" class="form-control" id="title" name="title"
           value="{{ old('title', $post->title ?? '') }}">
    @error('title')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="content" class="form-label">内容</label>
    <textarea class="form-control" id="content" name="content" rows="8">{{ old('content', $post->content ?? '') }}</textarea>
    @error('content')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="category" class="form-label">カテゴリー</label>
    <input type="text" class="form-control" id="category" name="category"
           value="{{ old('category', $post->category ?? '') }}">
    @error('category')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<div class="mb-3">
    <label for="image" class="form-label">画像</label>
    @if (!empty($post->image_url))
        <div class="mb-2">
            <img src="{{ $post->image_url }}" alt="" class="img-fluid" style="max-width: 200px;">
        </div>
    @endif
    <input type="file" class="form-control" id="image" name="image" accept="image/*">
    <div class="form-text">JPEG / PNG / WebP、2MBまで。選び直すと差し替わります。</div>
    @error('image')
        <p class="text-danger">{{ $message }}</p>
    @enderror
</div>

<button type="submit" class="btn btn-primary">{{ $submitLabel ?? '保存' }}</button>
