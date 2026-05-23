import argparse
import os

parser = argparse.ArgumentParser(description="List all files in the specified document directory")
parser.add_argument("--dir", required=True, help="Path to the document directory")
args = parser.parse_args()

tar_dir = args.dir

for _root, _dirs, files in os.walk(tar_dir):
    for file in files:
        # print(os.path.join(root, file))
        print(file)
